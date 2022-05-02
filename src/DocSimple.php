<?php
/**
 * Generador de documentación de código a partir de los bloques de comentarios incluidos en el código.
 *
 * Funciona con base en el modelo Javadoc adaptado según se describe en [phpDocumentor]
 * (https://docs.phpdoc.org/guide/guides/docblocks.html), donde se documenta en bloques de comentario dentro del script,
 * las clases y/o las funciones contenidas en el mismo.
 * Algunos tags a tener en cuenta:
 * (Referido de https://docs.phpdoc.org/guide/guides/docblocks.html)
 *
 * - author: Nombre del autor del elemento asociado.
 * - link: Relación entre el elemento asociado y una página web (referencias).
 * - param: (Sólo para funciones, métodos) Cada argumento de una función o método, uno por tag.
 * - return: (Sólo para funciones, métodos) Valor retornado por una función o método.
 * - since: Indica en qué versión el elemento asociado estuvo disponible.
 * - todo: Actividades o mejoras por realizar al elemento asociado.
 * - uses: Indica referencias a otros elementos.
 * - version: Versión actual del elemento estructural (a nivel de script más que de funciones o métodos).
 *
 * Tener presente que En PHP el bloque documento va antes de la definición de la función/clase. En lenguajes como Python va después.
 *
 * @uses miframe/functions
 *
 * @author John Mejia
 * @since Abril 2022
 */

namespace miFrame;

class DocSimple {

	private $solo_main_summary = false;
	private $tipodoc = '';
	private $interpreter = array();

	public $tags = array();
	public $debug = false;
	public $pathCache = '';

	public function __construct() {

		// Elementos para detectar el bloque de documentación en el código. Por defecto se define para PHP.

		$this->tags = array(
			'code-start' 		=> '<?',
			'code-start-full'	=> '<?php',					// Alias del tag de inicio (deben empezar igual)
			'code-end' 			=> '?>',
			'comments-start'	=> '//',
			'comments-end'		=> "\n",
			'comment-ml-start'	=> '/*',					// Inicio comentario multilinea
			'comment-ml-end'	=> '*/',
			'strings'			=> array('"', "'"),
			'strings-escape'	=> '\\',					// Ignora siguiente caracter dentro de una cadena
			'functions'			=> array('public function', 'private function', 'protected function', 'function', 'class', 'namespace'),
			'separators-end'	=> array('{', '}', ';'),
			'no-spaces'			=> array('(', ')', ','),	// Remueve espacios antes de este caracter
			'args-start'		=> '(',						// Marca inicio de argumentos en declaración de funciones
			'args-end'			=> ')',
		);

	}

	/**
	 * Descripción básica del elemento asociado.
	 *
	 * @param string $filename Nombre del archivo.
	 * @param mixed $required Elementos mínimos a incluir en el arreglo de respuesta.
	 * @return mixed Arreglo con los items de documentación.
	 */
	public function getSummary(string $filename, mixed $required = array()) {

		$this->solo_main_summary = true;
		$documento = $this->getDocumentation($filename);
		$this->solo_main_summary = false;

		$retornar = array();
		if (isset($documento['main'])) {
			$retornar = $documento['main'];
		}
		if (!isset($retornar['summary'])) {
			$retornar['summary'] = '';
		}
		if (!isset($retornar['since'])) {
			// Asume como fecha de creación la del archivo
			$retornar['since'] = miframe_filecreationdate($filename) . ' (A)';
		}
		// Garantiza existencia de valores minimos
		foreach ($required as $llave => $inicial) {
			if (!isset($retornar[$llave])) {
				$retornar[$llave] = $inicial;
			}
		}

		return $retornar;
	}

	/**
	 * Recupera los bloques de documentación del archivo $filename.
	 *
	 * @param string $filename
	 * @param string $search
	 * @return array Arreglo con todos los documentos recuperados.
	 */
	public function getDocumentation(string $filename, string $search = '') {

		$documento = array(
			// 'module' => $modulo . '/' . $submodulo,
			'file' 		=> $filename,
			// 'namespace' => '',
			'main' 		=> array(),
			'docs'  	=> array(),
			'errors'	=> array(),
			'index' 	=> array()
			);

		if (!file_exists($filename)) {
			$documento['errors'][] = 'Archivo base no existe (' . basename($filename) . ')';
			return $documento;
		}

		// PENDIENTE: Si se indica manejo de caché, buscar el archivo cacheado sea con fecha < que el real y > que este.
		$filecache = miframe_path($this->pathCache, basename($filename));
		if ($this->pathCache != '' && file_exists($filecache)) {

			// ...

		}
		else {
			$pos = strrpos($filename, '.');
			if ($pos === false) {
				$documento['errors'][] = 'No puede identificar el tipo de archivo a procesar (' . basename($filename) . ')';
				return $documento;
			}

			$extension = strtolower(substr($filename, $pos + 1));

			// PENDIENTE: Cargar codigo para recuperar informaciòn. Por defecto asume PHP
			if ($extension != 'php') {
				$documento['errors'][] = 'No puede documentar el tipo de archivo indicado (' . basename($filename) . ')';
				return $documento;
			}

			$documento['file'] = $filename;

			$contenido = file_get_contents($filename);

			// Contenedores
			$nuevo = array();
			$acum = '';
			$bloquedoc = array();

			// Elimina lineas de comentario, comillas y comentarios en bloque
			// asegurandose que un comentario no este en una cadena, etc.
			// Como pueden haber cadenas de texto inicializando parametros en funciones, no se pueden ignorar.
			// El proceso cumple varios objetivos:
			// * Elimina lineas en blanco (excepto aquellas dentro de un bloque de documentación).
			// * Elimina múltiples espacios en blanco contiguos (excepto aquellas dentro de un bloque de documentación).

			// Inicialización de variables de control
			$len = strlen($contenido);
			$es_codigo = ($this->tags['code-start'] == ''); // Si no hay tag de inicio, todo es codigo
			$esta_ignorando = false;
			$en_cadena = false;
			$es_documentacion = false;
			$tag_cierre = '';				// Contenedor para tags de cierre. Ej: "*/" para cierre de comentarios.
			$len_inicio = 2; 				// Tamaño mínimo para evaluar "<?", "//", ...
			if (!$es_codigo) { $len_inicio = strlen($this->tags['code-start']); }
			$len_car = $len_inicio;
			$len_full = strlen($this->tags['code-start-full']);
			$total_functions = 0;

			for ($i = 0; $i < $len; $i++) {
				$car = substr($contenido, $i, $len_car); // Lee de a dos carácteres
				if (!$es_codigo) {
					// Ignora todo hasta encontrar "<?"
					if ($car == $this->tags['code-start']) {
						$es_codigo = true;
						if ($this->tags['code-start-full'] != '' &&
							(substr($contenido, $i, $len_full) == $this->tags['code-start-full'])
							) {
								$i += $len_full - 1;
						}
						else { $i ++; }
					}
					// else { $acum .= '.'; } // Para debug...
				}
				else {
					// Dentro de código
					if (!$esta_ignorando && !$en_cadena) {
						if ($car === $this->tags['code-end']) {
							$acum .= $car;
							$es_codigo = false;
							$i ++; // Hasta el siguiente bloque luego del inicio
						}
						else {
							if (in_array($car[0], $this->tags['strings'])) {
								// Inicia comillas simples y dobles (no lo ignora pero lo identifica
								// para evitar interpretaciones erroneas de tags de control dentro de cadenas
								// de texto)-
								$tag_cierre = $car[0];
								$en_cadena = true;
							}
							elseif ($car === $this->tags['comments-start']) {
								// Inicia comentario sencillo
								$tag_cierre = $this->tags['comments-end'];
								$esta_ignorando = true;
								$i ++; // Lee siguiente bloque luego de apertura de comentario
							}
							elseif ($car === $this->tags['comment-ml-start']) {
								// Inicia bloque de comentario
								// Valida si es un bloque de documentación "/**\n"
								$esta_ignorando = true;
								$tag_cierre = $this->tags['comment-ml-end'];
								$es_documentacion = (
													$this->tags['comment-ml-start'] === '/*' &&
													// Ignora los fin de linea antes y después
													trim(substr($contenido, $i - 1, 5)) === '/**'
													);
								if (!$es_documentacion) {
									$i ++; // Lee siguiente bloque luego de apertura de comentario
								}
								else {
									if ($this->evalCodeBlock($acum, $bloquedoc, $nuevo)) {
										$total_functions ++;
									}
									// Redefine llave
									$acum = '';
									$i += 2;
								}
							}
							elseif (in_array($car[0], $this->tags['no-spaces'])) {
								$acum = rtrim($acum);
							}
						}
						if ($esta_ignorando || $en_cadena) {
							// Detectó combinación para ignorar
							$len_car = strlen($tag_cierre);
							if ($en_cadena) {
								$acum .= $car[0];
							}
							// else { $acum .= '.'; }
						}
						elseif ((!$es_documentacion && $car[0] === ' ') ||
							(!$es_documentacion && ($car[0] === ' ' || $car[0] === "\t"))
							) {
							// El ultimo elemento en $nuevo no debe ser un espacio
							if ($acum !== '' && trim(substr($acum, -1, 1)) !== '') {
								$acum .= ' ';
							}
						}
						elseif (in_array($car[0], $this->tags['separators-end'])) {
							$acum .= "\n";
						}
						elseif ($car[0] === "\n") {
							// El ultimo elemento en $nuevo no debe ser un espacio

							if ($acum != '') {
								if ($es_documentacion) {
									$acum .= $car[0];
								}
								else {
									// Ignora "\n" y lo cambia por un espacio
									if ($acum != '' && substr($acum, -1, 1) !== " ") {
										$acum .= " ";
									}
								}
							}
						}
						elseif ($car[0] !== "\r") {
							$acum .= $car[0];
						}
					}
					else {
						// Ignorando contenido
						if ($en_cadena && $car[0] === $this->tags['strings-escape']) {
							// Ignora hasta el siguiente bloque
							$i++;
							// Anexa el siguiente caracter
							$acum .= $car . substr($contenido, $i, 1);
						}
						elseif ($car === $tag_cierre) {
							// Encontró el tag de cierre
							$i += ($len_car - 1);
							$len_car = $len_inicio;

							if ($es_documentacion) {
								$bloquedoc = $this->evalDocBlock($acum);
								if ($total_functions <= 0) {
									$nuevo['main'] = $bloquedoc;
									$bloquedoc = array();
									$total_functions ++;
								}
							}
							elseif ($en_cadena) {
								$acum .= $car;
							}
							elseif ($tag_cierre === "\n" && !in_array($tag_cierre, $this->tags['separators-end'])) {
								// Ignora el "\n" y lo cambia por un espacio
								if ($acum != '' && substr($acum, -1, 1) !== " ") {
									$acum .= " ";
								}
							}
							elseif (in_array($tag_cierre, $this->tags['separators-end'])) {
									$acum .= "\n";
							}
							// else { $acum .= '.'; } // Para debug...

							$esta_ignorando = false;
							$en_cadena = false;
							$es_documentacion = false;
						}
						elseif ($es_documentacion || $en_cadena) {
							// Preserva contenido (documentación)
							$acum .= $car[0];
						}
						// else { $acum .= '.'; }  // Para debug...
					}
				}
			}

			$this->evalCodeBlock($acum, $bloquedoc, $nuevo);

			if (isset($nuevo['main'])) {
				$documento['main'] = $nuevo['main'];
				unset($nuevo['main']);
			}

			$documento['docs'] = $nuevo;

			// debug_box($documento);

			// Construye indice de funciones
			$documento['index'] = array();
			foreach ($nuevo as $k => $info) {
				if (isset($info['function'])) {
					$documento['index'][strtolower($info['function'])] = $k;
				}
			}

			// PENDIENTE: Si se indica manejo de caché, guardar
			if ($this->pathCache != '') {
			}
		}

		// Busca función indicada
		if ($search != '') {
			if (isset($documento['index'][$search])) {
				// Elimina todos los docs y deja solamente uno
				$documento['search'] = $documento['docs'][$documento['index'][$search]];
				$documento['docs'] = array();
			}
			else {
				$documento['errors'][] = 'No hay coincidencias para la busqueda realizada (' . htmlspecialchars($search) .')';
			}
		}

		return $documento;
	}

	/**
	 * Evalúa bloque de código encontrado entre bloques de documentación.
	 * Se supone que la primera definición de función/clase encontrada corresponde a aquel a
	 * que refiere el bloque de documentación previamente encontrado.
	 * Recomendaciones del texto a revisar:
	 * - Cada línea contiene bloques de código continuo, hasta encontrar un "{" (PHP).
	 * - Libre de más de un espacio en blanco entre palabras.
	 * - Libre de comentarios.
	 *
	 * @param string $text Texto con el código a procesar.
	 * @param array $docblock Bloque de documentación previamente encontrado.
	 * @param array $container Arreglo que acumula los descriptores.
	 * @return bool TRUE si pudo asignar el bloque de documentación a una función, FALSE en otro caso.
	 */
	private function evalCodeBlock(string $text, array &$docblock, array &$container) {

		$retornar = false;

		// Remueve acumulador actual si está en blanco
		if ($text != '') {
			// Rompe lineas y solo incluye las que tengan inicio con "functions-regexp"
			$text = trim($text);
			$lineas = explode("\n", $text);
			foreach ($lineas as $k => $linea) {
				$linea = trim($linea);
				$regexp = "/^(" . implode('|', $this->tags['functions']) . ")[\s\n](.*)/";
				preg_match($regexp, $linea, $matches);
				// Busca items asociados. El elemento "0" es la palabra que hace match, el segundo el resto
				if (count($matches) > 1) {
					// [2] contiene la función y los argumentos
					$args = '';
					if ($matches[1] == 'class') {
						$pos = strpos($matches[2], ' ');
						if ($pos !== false ) {
							$args = trim(substr($matches[2], $pos + 1));
							$matches[2] = substr($matches[2], 0, $pos);
						}
					}
					else {
						$pos = strpos($matches[2], $this->tags['args-start']);
						$fin = strrpos($matches[2], $this->tags['args-end']);
						if ($pos !== false && $fin !== false && $pos < $fin) {
							$args = trim(substr($matches[2], $pos + 1, $fin - $pos - 1));
							$matches[2] = substr($matches[2], 0, $pos);
						}
					}
					$container[] = array('type' => $matches[1], 'function' => $matches[2], 'function-args' => $args) + $docblock;
					$docblock = array();
					$retornar = true;
				}
			}
		}

		return $retornar;
	}

	/**
	 * Evalúa un bloque de código sanitizado previamente y recupera los atributos de documentación.
	 *
	 * @param string $text Texto con la documentación.
	 * @return array Arreglo con los datos del documento, (Ej. [ 'summary' => ..., 'desc' => ..., etc. ])
	 */
	private function evalDocBlock(string $text) {

		$text = trim($text);
		if ($text == '') { return; }

		$lineas = explode("\n", $text);
		$text = '';
		$bloquedoc = array();
		$acumdoc = '';
		$finlinea = array('.', ':');
		$es_pre = false;
		$tag_pre = '';

		foreach ($lineas as $k => $linea) {
			// Por defecto, todas las lineas de documentación validas empiezan con "*"
			$linea = trim($linea);
			if ($linea[0] === '*') {
				$linea = substr($linea, 1);

				// Bloque preformateado
				if ($es_pre) {
					$acumdoc .=  $linea . "\n";
				}

				$linea = trim($linea);

				// Valida si la linea continua definiciones de tags
				if (!$es_pre && substr($linea, 0, 1) !== '@' && $tag_pre != '') {
					$linea = '@' . $tag_pre . ' ' . $linea;
				}

				if ($linea === '```') {
					$es_pre = !$es_pre;
					if ($es_pre) { $acumdoc .= $linea . "\n"; }
				}
				elseif ($es_pre) {
					continue; // Nada mas por hacer
				}
				elseif (substr($linea, 0, 1) === '@') {
					// Es un tag de documentacion
					$arreglo = explode(' ', substr($linea, 1) . ' ', 2);
					$tag_doc = strtolower(trim($arreglo[0]));
					$arreglo[1] = trim($arreglo[1]);

					// Casos especiales:
					// @param (tipo) (variable) (descripcion)
					// @return (tipo) (descripcion)
					// @uses ... siempre se guarda como arreglo
					switch ($tag_doc) {
						case 'param':
							$arreglo = explode(' ', $arreglo[1] . '  ', 3);
							$arreglo[1] = trim($arreglo[1]);
							$arreglo[2] = trim($arreglo[2]);

							if (!isset($bloquedoc[$tag_doc][$arreglo[1]])) {
								$bloquedoc[$tag_doc][$arreglo[1]] = array('type' => trim($arreglo[0]), 'desc' => $arreglo[2]);
							}
							else {
								$bloquedoc[$tag_doc][$arreglo[1]]['desc'] .= ' ' . $arreglo[2];
							}
							$tag_pre = $tag_doc . ' ... ' . $arreglo[1];
							break;

						case 'return':
							$arreglo = explode(' ', $arreglo[1] . ' ', 2);
							if (!isset($bloquedoc[$tag_doc])) {
								$bloquedoc[$tag_doc] = array('type' => $arreglo[0], 'desc' => trim($arreglo[1]));
							}
							else {
								$bloquedoc[$tag_doc]['desc'] .= ' ' . $arreglo[1];
							}
							$tag_pre = $tag_doc;
							break;

						case 'uses':
							$bloquedoc[$tag_doc][] = $arreglo[1];
							break;

						case 'author':
						case 'since':
						case 'version':
						case 'todo':
						case 'link':
							// Los acumula directamente
							if (isset($bloquedoc[$tag_doc])) {
								if (!is_array($bloquedoc[$tag_doc]) && $bloquedoc[$tag_doc] != '') {
									$bloquedoc[$tag_doc] = array($bloquedoc[$tag_doc]);
								}
								$bloquedoc[$tag_doc][] = $arreglo[1];
							}
							else {
								$bloquedoc[$tag_doc] = $arreglo[1];
							}
							break;

						default:
							// Los agrupa bajo "others"
							if (isset($bloquedoc['others'][$tag_doc])) {
								if (!is_array($bloquedoc['others'][$tag_doc]) && $bloquedoc['others'][$tag_doc] != '') {
									$bloquedoc['others'][$tag_doc] = array($bloquedoc['others'][$tag_doc]);
								}
								$bloquedoc['others'][$tag_doc][] = $arreglo[1];
							}
							else {
								$bloquedoc['others'][$tag_doc] = $arreglo[1];
							}
						}
				}
				elseif ($linea == '' || in_array(substr($linea, -1, 1), $finlinea)) {
					if (!isset($bloquedoc['summary']) || $bloquedoc['summary'] == '') {
						$bloquedoc['summary'] = trim($acumdoc . ' ' . $linea);
						$acumdoc = '';
					}
					else {
						$this->docblock_connectlines($linea, $acumdoc);
						$acumdoc .= "\n";
					}
					// Remueve control de tags
					$tag_pre = '';
				}
				else {
					$this->docblock_connectlines($linea, $acumdoc);
					// Remueve control de tags
					$tag_pre = '';
				}
			}
		}

		$acumdoc = trim($acumdoc);
		if ($acumdoc != '') {
			$bloquedoc['desc'] = $acumdoc;
		}

		return $bloquedoc;
	}

	private function docblock_connectlines(string $linea, string &$acumdoc) {

		if ($linea != '') {
			if ($acumdoc != '') {
				$separador = ' ';
				if ($linea[0] === '-' || $linea[0] === '*' || $linea[0] === '>') {
					if (substr($acumdoc, -1, 1) !== "\n") {
						$separador = "\n";
					}
				}
				$acumdoc .= $separador . $linea;
			}
			else { $acumdoc = $linea; }
		}
	}

	/**
	 * Retorna la documentación encontrada en formato HTML.
	 * Si se usa con $clickable = TRUE habilida las funciones como enlace usando el nombre "docfunction" para indicar
	 * el nombre de la función invocada.
	 *
	 * @param string $filenameººº
	 * @param bool $clickable TRUE para hacer el documento navegable.
	 * @param bool $with_styles TRUE para incluir estilos css. FALSE no los incluye.
	 */
	public function getDocumentationHTML(string $filename, bool $clickable = false, bool $with_styles = true) {

		$funcion = '';
		$titulo = htmlspecialchars(basename($filename));
		if ($clickable && isset($_REQUEST['docfunction']) && $_REQUEST['docfunction'] != '') {
			$funcion = trim($_REQUEST['docfunction']);
			// Enlace de retorno
			$data = $_GET;
			unset($data['docfunction']);
			$url = '?' . http_build_query($data);
			$titulo = '<p><a href="' . $url . '">' . $titulo . '</a></p>';
		}

		$documento = $this->getDocumentation($filename, $funcion);

		if ($with_styles) {
			$salida = '
<style>
	.docblock { border:1px solid #d0d7de; border-radius:6px; font-family: "Segoe UI"; font-size:16px; margin:10px 32px; padding-bottom:10px; }
	.docblock div { padding: 10px; }
	.docblock p { padding: 5px 10px; margin:0; border-radius:6px; }
	.docblock pre { border:1px dashed #d0d7de; margin:10px 64px; padding:14px; background-color:#f6f8fa;}
	.docblock ul { padding: 5px 10px 5px 32px; margin:0; }
	.docblock li { padding-bottom: 5px; }
	.docblock blockquote { border-left: 10px solid #d0d7de; padding:14px 15px; margin: 10px 64px; }
	.docblock .docfile { background-color:#f6f8fa; padding-left:20px; margin-bottom:10px; border-bottom:1px solid #d0d7de; font-weight:600; }
	.docblock .docsummary { padding-left: 20px; }
	.docblock .docinfo { padding-left:20px; }
	.docblock .docfunction { border-bottom:1px solid #d0d7de; font-weight:600; padding-left:20px; font-size:20px; padding-bottom:14px; margin-bottom:20px; }
	.docblock .docerrors { border:1px solid darkred; margin-left:10px; margin-right:10px;}
</style>' . PHP_EOL;
		}

		$salida .= '<div class="docblock">' . PHP_EOL .
				'<div class="docfile">' .
					$titulo .
				'</div>' . PHP_EOL;

		// Errores encontrados
		if (count($documento['errors']) > 0) {
			$salida .= '<div class="docerrors"><ul><li>' . implode('</li><li>', $documento['errors']) . '</li></ul></div>' . PHP_EOL;
		}

		// Bloque principal
		if (isset($documento['main']) && $funcion == '') {
			$main = $documento['main'];
			$salida .= $this->evalHTMLDoc($main, $documento['docs'], $clickable);
		}
		elseif (isset($documento['search'])) {
			// Publica descripción de función
			$salida .= $this->evalHTMLDoc($documento['search'], array(), $clickable);
		}

		$salida .= PHP_EOL . '</div>';

		return $salida;
	}

	private function evalHTMLDoc(mixed $main, mixed $contents = array(), bool $clickable = false) {

		$salida = '';

		$sintaxis = '';

		// Solo para funciones
		if (isset($main['function']) && $main['function'] != '') {
			$salida .= '<p class="docfunction">' . htmlspecialchars($main['function']) . '</p>';
			$sintaxis = '<pre class="docsintaxis">' . $main['type'] . ' ' . $main['function'];
			if ($main['type'] != 'class') {
				$sintaxis .= '(' . $main['function-args'] . ')' ;
				if (isset($main['return'])) {
					$sintaxis .= ' : ' . $main['return']['type'];
				}
			}
			elseif (isset($main['args'])) {
				$sintaxis .= ' ' . $main['args'];
			}
			$sintaxis .= '</pre>';
		}

		if (isset($main['summary']) && $main['summary'] != '') {
			$salida .= '<p class="docsummary">' . htmlspecialchars($main['summary']) . '</p>';
		}

		$salida .= $sintaxis;

		if (isset($main['desc']) && $main['desc'] != '') {

			$lineas = explode("\n", $main['desc']);
			$main['desc'] = '';

			$es_pre = false;
			$tags_acum = array();

			foreach ($lineas as $k => $linea) {

				if ($es_pre) {
					$main['desc'] .= htmlspecialchars($linea) . PHP_EOL;
				}

				$linea = trim($linea);
				if ($linea === '') { continue; }

				// Texto preformateado
				if ($linea === '```') {
					// Toogle valor
					$es_pre = !$es_pre;
					if ($es_pre) {
						$main['desc'] .= $this->docblock_opentags($tags_acum, 'pre');
					}
					else {
						$main['desc'] .= $this->docblock_closetags($tags_acum, 'pre') . PHP_EOL;
					}
					$linea = '';
				}
				elseif ($es_pre) {
					continue; // Nada mas por hacer
				}
				// Listas
				elseif ($linea[0] == '-' || $linea[0] == '*') {
					$main['desc'] .= $this->docblock_opentags($tags_acum, 'ul');
					$main['desc'] .= '<li>' . htmlspecialchars(trim(substr($linea, 1))). '</li>';
					$linea = '';
				}
				// Blockquote
				elseif ($linea[0] == '>') {
					$main['desc'] .= $this->docblock_opentags($tags_acum, 'blockquote');
					$main['desc'] .= htmlspecialchars(trim(substr($linea, 1)));
					$linea = '';
				}
				else {
					$main['desc'] .= $this->docblock_closetags($tags_acum, '');
				}

				if ($linea != '') {
					$main['desc'] .= '<p>' . htmlspecialchars($linea) . '</p>' . PHP_EOL;
				}
			}

			// Valida si terminó con un tabulado abierto
			$main['desc'] .= $this->docblock_closetags($tags_acum, '');

			$salida .= '<div class="docdesc">' . $main['desc'] . '</div>' . PHP_EOL;
		}

		if (count($contents) > 0) {
			// Funciones y/o metodos
			$arreglo = array();
			$titulo = 'Funciones';
			$summary = '';
			$namespace = '';

			foreach ($contents as $k => $info) {
				if (isset($info['type']) && $info['type'] == 'namespace') {
					$namespace = $info['function'] . '\\';
				}
				elseif (isset($info['type']) && $info['type'] == 'class') {
					// Información de clase
					// Si hay datos previos en $arreglo, exporta antes de continuar
					if (count($arreglo) > 0) {
						ksort($arreglo);
						$salida .= '<div class="docfun"><p><b>' . $titulo . '</b></p>' . $summary . '<ul><li>' . implode('</li><li>', $arreglo) . '</li></ul></div>' . PHP_EOL;
						$arreglo = array();
					}

					$titulo = 'Class ' . $namespace . $info['function'];
					if (isset($info['summary']) && $info['summary'] != '') {
						$summary = '<p>' . $info['summary'] . '</p>';
					}
					if ($clickable) {
						$data = $_GET;
						$data['docfunction'] = strtolower($info['function']);
						$url = '?' . http_build_query($data);
						$summary .= '<p><a href="' . $url . '">Ver detalles</a></p>';
					}
				}
				elseif (isset($info['function'])) {
					$function = strtolower($info['function']);
					if (!$clickable) {
						$arreglo[$function] = '<b>' . htmlspecialchars($info['function']) . '</b>';
					}
					else {
						// Determinar si llega por GET o POST la data principal?
						$data = $_GET;
						$data['docfunction'] = strtolower($info['function']);
						$url = '?' . http_build_query($data);
						$arreglo[$function] = '<a href="' . $url . '">' . htmlspecialchars($info['function']) . '</a>';
					}
					if (isset($info['summary']) && $info['summary'] != '') {
						$arreglo[$function] .= ' -- ' . htmlspecialchars($info['summary']);
					}
				}
			}

			if (count($arreglo) > 0) {
				ksort($arreglo);
				$salida .= '<div class="docfun"><p><b>' . $titulo . '</b></p>' . $summary . '<ul><li>' . implode('</li><li>', $arreglo) . '</li></ul></div>' . PHP_EOL;
			}
		}

		if (isset($main['uses'])) {
			foreach ($main['uses'] as $k => $info) {
				$arreglo = explode(' ', $info . ' ', 2);
				$arreglo[1] = trim($arreglo[1]);
				$main['uses'][$k] = '<b>' . htmlspecialchars(strtolower($arreglo[0])) . '</b>';
				if ($arreglo[1] != '') {
					$main['uses'][$k] .= ' -- ' . htmlspecialchars($arreglo[1]);
				}
			}
			$salida .= '<div class="docuses"><p><b>Requisitos:</b></p><ul><li>' . implode('</li><li>', $main['uses']) . '</li></ul></div>' . PHP_EOL;
		}

		if (isset($main['param'])) {
			foreach ($main['param'] as $param => $info) {
				$main['param'][$param] = '<b>' . $param . '</b> (' . $info['type'] . ') ' . $info['desc'];
			}
			$salida .= '<div class="docparam"><p><b>Parámetros</b></p><ul><li>' . implode('</li><li>', $main['param']) . '</li></ul></div>' . PHP_EOL;
		}

		if (isset($main['return'])) {
			$salida .= '<div class="docreturn"><p><b>Valores retornados</b></p><ul><li>' . $main['return']['desc'] . '</li></ul></div>' . PHP_EOL;
		}

		$comunes = array('version' => 'Versión', 'author' => 'Autor', 'since' => 'Creado en');
		foreach ($comunes as $llave => $titulo) {
			if (isset($main[$llave])) {
				if (is_array($main[$llave])) { $main[$llave] = implode(', ', $main[$llave]); }
				$salida .= '<p class="docinfo"><b>' . $titulo . ':</b> ' . nl2br(htmlspecialchars($main[$llave])) . '</p>';
			}
		}

		return $salida;
	}

	private function docblock_opentags(array &$tags_acum, string $tag) {

		$acum = '';
		$i = count($tags_acum) - 1;
		if ($i < 0 || $tags_acum[$i] != $tag) {
			while ($i >= 0 && $tags_acum[$i] != $tag) {
				// Cierra todo hasta encontrar uno igual o llegar a ceros
				$acum .= '</' . $tags_acum[$i] . '>';
				unset($tags_acum[$i]);
				$i --;
			}
			$tags_acum[] = $tag;
			$acum .= '<' . $tag . '>';
		}
		return $acum;
	}

	private function docblock_closetags(array &$tags_acum, string $tag) {

		$acum = '';
		$i = count($tags_acum) - 1;
		while ($i >= 0) {
			$actual = $tags_acum[$i];
			unset($tags_acum[$i]);
			$acum .= '</' . $actual . '>';
			if ($actual == $tag) {
				break;
			}
			$i--;
		}

		return $acum;
	}

}
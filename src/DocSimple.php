<?php
/**
 * Generador de documentación de código a partir de los bloques de comentarios
 * incluidos en el código.
 *
 * Funciona con base en el modelo Javadoc adaptado según se describe en phpDocumentor
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
 * En caso de encontrar documentación faltante, se reportan en el arreglo de salida agrupados bajo el item "errors".
 *
 * @uses miframe/common/functions
 * @author John Mejia
 * @since Abril 2022
 */

namespace miFrame\Utils;

/**
 * Clase para obtener la documentación del código a partir de los bloques de comentarios.
 * Las siguientes propiedades públicas pueden ser usadas:
 *
 * - $tags:  array. Atributos para evaluar documentación. Se predefine en el __construct() de la clase para soportar el modelo PHP Javadoc.
 * - $debug: boolean. TRUE para incluir mensajes de depuración.
 * - $evalRequiredItems: boolean. TRUE para evaluar elementos mínimos requeridos. Se reportan en el arreglo de salida agrupados
 * 		bajo el item "errors".
 * - $usesFunction: Función a usar al mostrar elemento "@uses" en `$this->getDocumentationHTML()`. Retorna texto HTML. Ej:
 * 		function ($modulo, $infomodulo) { ... return $html; }
 * - $parserTextFunction: Función a usar para interpretar el texto (asumiendo formato Markdown). Retorna texto HTML. Ej:
 * 		function (text) { ... return $html; }
 */
class DocSimple {

	private $tipodoc = '';
	private $interpreter = array();
	private $cache = array();

	public $tags = array();
	public $debug = false;
	public $evalRequiredItems = true;
	public $usesFunction = false;
	public $parserTextFunction = false;

	public function __construct() {

		// Elementos para detectar el bloque de documentación en el código. Por defecto se define para PHP.

		// $this->regex_comment = "/^(\/\/|#)(.*)/";
		// $this->regex_assoc = "/^(class|private function|public function|protected function|function)[\s\n](.*)/";
		// $this->regex_eval = array(
		// 	'class' => array("/^(\S+)?([\s\n].*)\{(.*)/", " $1"),
		// 	'*' => array("/^(\S+)[\s\n]*\([\s\n]{0,}(.*)[\s\n]{0,}\)[\s\n]{0,}\{(.*)/", " $1($2)")

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
															// Declaraciòn de funciones/clases
			'separators-end'	=> array('{', '}', ';'),
			'no-spaces'			=> array('(', ')', ','),	// Remueve espacios antes de este caracter
			'args-start'		=> '(',						// Marca inicio de argumentos en declaración de funciones
			'args-end'			=> ')',
			'eval-args'			=> '/\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)/',
															// regexp para validar variables dentro de los argumentos de la función
		);

		// Inicializa elemento "file" para prevenir error al validar la primera vez
		$this->cache = array('file' => '');
	}

	/**
	 * Retorna el nombre del último archivo procesado.
	 *
	 * @return string Path completo del archivo procesado.
	 */
	public function filename() {
		return $this->cache['file'];
	}

	/**
	 * Descripción básica del elemento asociado.
	 *
	 * @param string $filename Nombre del archivo.
	 * @param mixed $required Elementos mínimos a incluir en el arreglo de respuesta.
	 * @return mixed Arreglo con los items de documentación.
	 */
	public function getSummary(string $filename, mixed $required = array()) {

		$documento = $this->getDocumentation($filename, '', true);

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
	 * Retorna un arreglo con la siguiente estructura:
	 * - file: Nombre del archivo ($filename).
	 * - main: Documentación del script (corresponde al primer bloque de documentación encontrado).
	 * - docs: Elementos asociados (funciones, clases, métodos).
	 * - errors: Errores encontrados.
	 * - index: Indice de funciones y su ubicación en el arreglo "docs", para agilizar busquedas.
	 * - debug: Mensajes de depuración (solamente cuando modo debug es TRUE).
	 *
	 * @param string $filename Nombre del script del que se va a recuperar la documentación.
	 * @param string $search_function Nombre de la función a buscar.
	 * @param bool $only_summary TRUE retorna solamente el bloque de resumen (summary). FALSE retorna toda la documentación.
	 * @return array Arreglo con todos los documentos recuperados.
	 */
	public function getDocumentation(string $filename, string $search_function = '', bool $only_summary = false) {

		$documento = array(
			// 'module' => $modulo . '/' . $submodulo,
			'file' 		=> $filename,
			// 'namespace' => '',
			'main' 		=> array(),
			'docs'  	=> array(),
			'errors'	=> array(),
			'index' 	=> array(),
			'debug'		=> array()
			);

		// Texto a usar en mensajes de error
		$archivo = $filename;
		if (!$this->debug) { $archivo = basename($filename); }

		if (!file_exists($filename)) {
			$documento['errors'][] = 'Archivo base no existe (' . $archivo . ')';
			return $documento;
		}

		// Valida si coincide con la más reciente captura.
		if (strtolower($this->cache['file']) === strtolower($filename) && !$only_summary) {
			$documento = $this->cache;
			if (is_debug_on()) {
				$documento['debug'][] = 'Recuperado de caché previo';
			}
		}
		else {
			$extension = miframe_extension($filename);
			if ($extension == '') {
				$documento['errors'][] = 'No puede identificar el tipo de archivo a procesar (' . $archivo . ')';
				return $documento;
			}

			// PENDIENTE: Cargar codigo para recuperar informaciòn. Por defecto asume PHP
			if ($extension != '.php') {
				$documento['errors'][] = 'No puede documentar el tipo de archivo indicado (' . $archivo . ')';
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
						if ($this->tags['code-start-full'] != ''
							&& (substr($contenido, $i, $len_full) == $this->tags['code-start-full'])
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
													$this->tags['comment-ml-start'] === '/*'
													// Ignora los fin de linea antes y después
													&& trim(substr($contenido, $i - 1, 5)) === '/**'
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
								$bloquedoc = $this->evalDocBlock($acum, $filename);
								if ($total_functions <= 0) {
									$nuevo['main'] = $bloquedoc;
									$bloquedoc = array();
									$total_functions ++;
									// Si solo requiere sumario, abandona el resto
									if ($only_summary) { break; }
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

			/*if (isset($nuevo['namespace'])) {
				$documento['namespace'] = $nuevo['namespace'];
				unset($nuevo['namespace']);
			}*/

			if (isset($nuevo['main'])) {
				// Evalua requeridos
				if (!$only_summary) {
					$this->docblock_required_items($documento['errors'], $nuevo['main'], true);
				}
				// Reasigna main
				$documento['main'] = $nuevo['main'];
				unset($nuevo['main']);
			}

			if (!$only_summary) {
				$documento['docs'] = $nuevo;

				// debug_box($documento);

				// Construye indice de funciones
				$documento['index'] = array();
				foreach ($nuevo as $k => $info) {
					if (isset($info['function'])) {
						$documento['index'][strtolower($info['function'])] = $k;
						// Revisa requeridos
						$this->docblock_required_items($documento['errors'], $info);
					}
				}

				// Preserva captura
				$this->cache = $documento;
			}
		}

		// Busca función indicada
		if ($search_function != '') {
			if (isset($documento['index'][$search_function])) {
				// Elimina todos los docs y deja solamente uno
				$documento['docfunction'] = $documento['docs'][$documento['index'][$search_function]];
				$documento['docs'] = array();
			}
			else {
				$documento['errors'][] = 'No hay coincidencias para la busqueda realizada (' . htmlspecialchars($search_function) .')';
			}
		}

		return $documento;
	}

	/**
	 * Evalua elementos requeridos y genera mensajes de error.
	 * Si $this->evalRequiredItems es false no realiza esta validación.
	 *
	 * @param array $errors Arreglo editable dónde serán registrados los mensajes de error.
	 * @param array $info Bloque de documentación a revisar.
	 * @param bool $is_main TRUE para indicar que $info corresponde al bloque principal (descripción del script).
	 */
	private function docblock_required_items(array &$errors, array $info, bool $is_main = false) {

		if (!$this->evalRequiredItems) { return; }

		$ignore_summary = false;
		$origen = 'el script';
		if (isset($info['function'])) {
			$origen = '<b>' . $info['function'] . '</b>';
			$ignore_summary = (
					in_array($info['function'], [ '__construct' ])
					|| in_array($info['type'], [ 'namespace', 'class' ])
					);
		}

		if (!isset($info['summary']) || $info['summary'] == '') {
			if (!$ignore_summary) {
				$errors[] = 'No se ha documentado resumen para ' . $origen;
			}
		}

		if (!isset($info['author']) && $is_main) {
			$errors[] = 'No se ha documentado el autor del script';
		}

		if (isset($info['function-args']) && $info['function-args'] != ''){
			if (!isset($info['param'])) {
				$errors[] = 'No se ha documentado @param en ' . $origen;
			}
			elseif ($info['function-args'] != '') {
				$args = $info['function-args'];
				// Tomado de https://stackoverflow.com/questions/19562936/find-all-php-variables-with-preg-match
				$pattern = $this->tags['eval-args'];
				if ($pattern != '') {
					// Los resultados quedan en $matches[0]
					$result = preg_match_all($pattern, $args, $matches);
					if ($result > 0) {
						// debug_box($matches, $args);
						foreach ($matches[0] as $k => $param) {
							if (!isset($info['param'][$param])) {
								$info['param'][$param] = '?';
							}
							else {
								unset($info['param'][$param]);
							}
						}
						if (count($info['param']) > 0) {
							$errors[] = 'Hay argumentos @param no documentados en ' . $origen . ' (' . implode(', ', array_filter(array_keys($info['param']))) . ')';
						}
					}
				}
			}
		}
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
				/*elseif ($this->tags['namespace'] != '') {
					// Este es un caso especial, donde asigna un nombre de espacio al sistema
					$regexp = "/^(" . $this->tags['namespace'] . ")[\s\n](.*)/";
					preg_match($regexp, $linea, $matches);
					if (count($matches) > 1) {
						$container['namespace'] = $matches[2];
					}
				}*/
			}
		}

		return $retornar;
	}

	/**
	 * Evalúa un bloque de código sanitizado previamente y recupera los atributos de documentación.
	 *
	 * A tener en cuenta algunas definiciones de texto Markdown a mantener:
	 *
	 * - Un parrafo es una o más líneas de texto consecutivas separadas por uno o más líneas en blanco. No indente parrafos normales con espacios o tabs.
	 *   También se considera como fin de parrafo un punto o ":" al final de la línea.
	 * - Usar ">" para indicar un texto como "blockquote".
	 * - Cuatro espacios en blanco al inicio o un tab, indican un texto preformateado. De forma similar, texto enmarcado por "```" también
	 *   se considera como texto preformateado.
	 * - Listas empiezan con "*", "+" o "-". Un "*" precedido de cuatro espacios o un tab, indica que es una sublista de un item de lista
	 *   previo.
	 *
	 * @link https://bitbucket.org/tutorials/markdowndemo/src/master/
	 * @param string $text Texto con la documentación.
	 * @return array Arreglo con los datos del documento, (Ej. [ 'summary' => ..., 'desc' => ..., etc. ])
	 */
	private function evalDocBlock(string $text) {

		$text = trim($text);
		if ($text == '') { return; }

		$lineas = explode("\n", $text);
		$text = ''; // Libera memoria

		$bloquedoc = array();
		$finlinea = array('.', ':');
		$es_pre = false;
		$es_tags = false;

		$total_lineas = count($lineas);
		$n = 0;

		// debug_box($lineas, 'PRE');

		// Purga las líneas buscando aquellas que sean documentación
		for ($i = 0; $i < $total_lineas; $i ++) {
			// Por defecto, todas las lineas de documentación validas empiezan con "* ".
			$linea = trim($lineas[$i]);
			$lineas[$i] = ''; // Limpia

			if ($linea === '*') {
				// Línea vacia
				$this->docblock_newline($lineas, $n);
				continue;
			}
			elseif (substr($linea, 0, 2) !== '* ') {
				// No es una línea valida para documentación
				continue;
			}
			else {
				// Remueve marca
				$linea = substr($linea, 2);
			}

			$slinea = trim($linea);

			// Remplaza cuatro espacios por un TAB
			$linea = str_replace('    ', "\t", $linea);

			if ($linea === '```') {
				$es_pre = !$es_pre;
			}

			// Texto preformateado convencional (no aplica si la línea actual es una lista)
			if (trim($linea[0]) === "@") {
				if ($lineas[$n] != '') { $n ++; }
				$lineas[$n] = $linea;
				$n ++;
				$es_tags = true;
			}
			/*elseif ($lineas[$n] !== '') {
				$lineas[$n] .= ' ' . $slinea;
			}*/
			elseif ($es_tags && $linea[0] == "\t") {
				// Documentando tags, asume es parte de la misma linea
				$lineas[$n - 1] .= "\n" . $linea;
			}
			else {
				$es_tags = false;
				// Fin de parrafo convencional
				if ($n == 1 && substr($lineas[0], -1, 1) != "\n") {
					// Parte del summary (primer linea)
					$linea = $lineas[0] . ' ' . trim($linea);
					$n = 0;
				}
				if (!$es_pre && $linea[0] !== "\t" && $slinea[0] != '>' && in_array(substr($linea, -1, 1), $finlinea))  {
					$lineas[$n] = $linea;
					$this->docblock_newline($lineas, $n);
				}
				else {
					$lineas[$n] = $linea;
					$n ++;
				}
			}
		}

		$lineas = array_filter($lineas);

		// debug_box($lineas, 'POS');

		// Ahora si evalua cada línea
		$total_lineas = count($lineas);

		// Purga las líneas buscando aquellas que sean documentación
		for ($i = 0; $i < $total_lineas; $i ++) {
			$linea = trim($lineas[$i]);
			if ($linea[0] === '@') {
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
						$bloquedoc[$tag_doc][$arreglo[1]] = array('type' => trim($arreglo[0]), 'desc' => $arreglo[2]);
						break;

					case 'return':
						$arreglo = explode(' ', $arreglo[1] . ' ', 2);
						$bloquedoc[$tag_doc] = array('type' => $arreglo[0], 'desc' => trim($arreglo[1]));
						break;

					case 'uses':
						$arreglo = explode(' ', $arreglo[1] . ' ', 2);
						$arreglo[0] = trim($arreglo[0]);
						$arreglo[1] = trim($arreglo[1]);
						$bloquedoc[$tag_doc][$arreglo[0]] = $arreglo[1];
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
			elseif (!isset($bloquedoc['summary']) || $bloquedoc['summary'] == '') {
				$bloquedoc['summary'] = $lineas[$i];
				}
			elseif (!isset($bloquedoc['desc']) || $bloquedoc['desc'] == '') {
					$bloquedoc['desc'] = $lineas[$i];
				}
			else {
				$bloquedoc['desc'] .= "\n" . $lineas[$i];
			}
		}

		return $bloquedoc;
	}

	/**
	 * Adiciona fin de línea para que el interpretador lo tome correctamente.
	 *
	 * @param array $lines Arreglo editable de líneas.
	 * @param int $index Indice editable con la línea del arreglo $lines actualmente en revisión.
	 */
	private function docblock_newline(array &$lines, int &$index) {

		if ($lines[$index] != '') {
			$lines[$index] .= "\n";
			$index ++;
		}
		elseif ($index > 0 && $lines[$index - 1] != '' && substr($lines[$index - 1], -1, 1) != "\n") {
			$lines[$index - 1] .= "\n";
		}
	}

	/**
	 * Retorna la documentación encontrada en formato HTML.
	 * Si se usa con $clickable = TRUE habilida las funciones como enlace usando el nombre "docfunction" para indicar
	 * el nombre de la función invocada.
	 * Intenta interpretar los textos asumiendo formato "Markdown" para generar un texto HTML equivalente. Si no se define una
	 * función externa para este fin ($this->parserTextFunction) hace uso de la función interna docblock_parserlocal().
	 *
	 * @param string $filename
	 * @param bool $clickable TRUE para hacer el documento navegable.
	 * @param bool $show_errors TRUE para incluir listado de errores encontrados. FALSE los omite.
	 * @param bool $with_styles TRUE para incluir estilos css. FALSE no los incluye.
	 * @return string Texto HTML.
	 */
	public function getDocumentationHTML(string $filename, bool $clickable = false, bool $show_errors = true, bool $with_styles = true) {

		$funcion = '';
		$titulo = htmlspecialchars(basename($filename));
		if ($clickable && isset($_REQUEST['docfunction']) && $_REQUEST['docfunction'] != '') {
			$funcion = trim($_REQUEST['docfunction']);
			// Enlace de retorno
			$titulo .= ' ' . $this->parserLink('Regresar a Descripción general', '');
		}

		$documento = $this->getDocumentation($filename, $funcion);

		if ($with_styles) {
			$salida = '
<style>
	.docblock { border:1px solid #d0d7de; border-radius:6px; font-family: "Segoe UI"; font-size:16px; margin:32px; padding-bottom:20px; }
	.docblock div { padding: 10px 20px; }
	.docblock p { padding: 5px 10px; margin:0; border-radius:6px; }
	.docblock pre { border:1px dashed #d0d7de; margin:10px 64px; padding:14px; background-color:#f6f8fa; }
	.docblock code { background-color:#f6f8fa; }
	.docblock pre code { padding:0; }
	.docblock ul { padding: 5px 10px 5px 32px; margin:0; }
	.docblock li { padding-bottom: 5px; }
	.docblock h2, .docblock .docnonav h1 { font-size:20px; margin-top:20px; border-bottom:none; }
	.docblock .docnonav h2 { font-size:16px; margin-top:10px; }
 	.docblock blockquote { border-left: 10px solid #d0d7de; padding:14px 15px; margin: 10px 64px; }
	.docblock .docfile { background-color:#f6f8fa; padding-left:20px; margin-bottom:10px; border-bottom:1px solid #d0d7de; font-weight:600; }
	.docblock .docfile a { dislay:block; float:right; font-size:14px; }
	.docblock .docinfo { padding-left:20px; }
	.docblock .docfunction { border-bottom:1px solid #d0d7de; font-weight:600; padding-left:20px; font-size:20px; padding-bottom:14px; margin-bottom:20px; }
	.docblock .docerrors { border:1px solid darkred; color:darkred; font-size:14px; margin:20px 10px;}
</style>' . PHP_EOL;
		}

		$salida .= '<div class="docblock">' . PHP_EOL .
				'<div class="docfile">' .
					$titulo .
				'</div>' . PHP_EOL;

		// Errores encontrados
		if (count($documento['errors']) > 0 && $funcion == '' && $show_errors) {
			$salida .= '<div class="docerrors"><ul><li>' . implode('</li><li>', $documento['errors']) . '</li></ul></div>' . PHP_EOL;
		}

		// Bloque principal
		if (isset($documento['main']) && $funcion == '') {
			$main = $documento['main'];
			$salida .= $this->evalHTMLDoc($main, $documento['docs'], $clickable);
		}
		elseif (isset($documento['docfunction'])) {
			// Publica descripción de función
			$salida .= $this->evalHTMLDoc($documento['docfunction'], array(), $clickable);
		}

		if (!$clickable) {
			// Muestra todas las funciones en la misma vista
			$salida .= '<div class="docnonav"><h1>Contenido</h1>';
			foreach ($documento['docs'] as $k => $info) {
				if ($info['type'] != 'namespace') {
					$salida .= $this->evalHTMLDoc($info, array(), $clickable)  . PHP_EOL;
				}
			}
			$salida .= '</div>' . PHP_EOL;
		}

		$salida .= PHP_EOL . '</div>';

		return $salida;
	}

	/**
	 * Procesa contenido y genera texto HTML equivalente.
	 *
	 * @param mixed $main Bloque principal de documentación (summary, descripción, params, etc.).
	 * @param mixed $contents Funciones/métodos asociadas.
	 * @param bool $clickable TRUE genera enlaces en los nombres de las funciones/métodos y no incluye su detalle
	 * 		en el texto generado. FALSE no incluye enlaces pero si el detalle de todas las funciones/métodos asociadas.
	 * @return string Texto HTML.
	 */
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
			$salida .= '<div class="docsummary">' . $this->parserText($main['summary']) . '</div>';
		}

		$salida .= $sintaxis;

		if (isset($main['desc']) && $main['desc'] != '') {
			$salida .= '<div class="docdesc">' . $this->parserText($main['desc']) . '</div>' . PHP_EOL;
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
						$summary = $this->parserText($info['summary']);
					}
					if ($clickable) {
						$summary .= '<p>' . $this->parserLink('Ver detalles', $info['function']) . '</p>';
					}
				}
				elseif (isset($info['function'])) {
					$function = strtolower($info['function']);
					if (!$clickable) {
						$arreglo[$function] = '<b>' . htmlspecialchars($info['function']) . '</b>';
					}
					else {
						// Determinar si llega por GET o POST la data principal?
						$arreglo[$function] = $this->parserLink($info['function'], $info['function']);
					}
					if ($info['type'] != 'public function' && $info['type'] != 'function') {
						$arreglo[$function] .= ' (' . $info['type'] . ')';
					}
					if (isset($info['summary']) && $info['summary'] != '') {
						$arreglo[$function] .= ' -- ' . $this->parserText($info['summary'], true);
					}
				}
			}

			if (count($arreglo) > 0) {
				ksort($arreglo);
				$salida .= '<div class="docfun"><h2>' . $titulo . '</h2>' . $summary . '<ul><li>' . implode('</li><li>', $arreglo) . '</li></ul></div>' . PHP_EOL;
			}
		}

		if (isset($main['uses'])) {
			foreach ($main['uses'] as $modulo => $info) {
				if ($modulo != '' && $clickable && is_callable($this->usesFunction)) {
					$main['uses'][$modulo] = call_user_func($this->usesFunction, $modulo, $info);
				}
				else {
					$main['uses'][$modulo] = '<b>' . htmlspecialchars(strtolower($modulo)) . '</b>';
					if ($info != '') {
						$main['uses'][$modulo] .= ' ' . $this->parserText($info);
					}
				}
			}
			$salida .= '<div class="docuses"><h2>Requisitos</h2><ul><li>' . implode('</li><li>', $main['uses']) . '</li></ul></div>' . PHP_EOL;
		}

		if (isset($main['param'])) {
			foreach ($main['param'] as $param => $info) {
				$main['param'][$param] = '<b>' . $param . '</b> (' . $info['type'] . ') ' . $this->parserText($info['desc'], true);
			}
			$salida .= '<div class="docparam"><h2>Parámetros</h2><ul><li>' . implode('</li><li>', $main['param']) . '</li></ul></div>' . PHP_EOL;
		}

		if (isset($main['return'])) {
			$salida .= '<div class="docreturn"><h2>Valores retornados</h2><ul><li>' . $this->parserText($main['return']['desc'], true) . '</li></ul></div>' . PHP_EOL;
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

	/**
	 * Genera enlace para navegación de funciones en documentación HTML.
	 *
	 * @param string $titulo Título del enlace.
	 * @param string $function Nombre de la función a buscar.
	 * @param string $param Nombre del parámetro que indica el nombre de la función a buscar. En blanco asume "docfunction".
	 * @return string Enlace en formato HTML.
	 */
	public function parserLink(string $titulo, string $function, string $param = '') {

		$data = array();
		if (count($_GET) > 0) {
			$data = $_GET;
		}
		elseif (count($_POST) > 0) {
			$data = $_POST;
		}
		if ($param == '') { $param = 'docfunction'; }
		$function = trim($function);
		if ($function == '' && isset($data[$param])) {
			unset($data[$param]);
		}
		else {
			$data[$param] = strtolower($function);
		}
		$url = '?' . http_build_query($data);
		$enlace = '<a href="' . $url . '">' . htmlspecialchars($titulo) . '</a>';

		return $enlace;
	}

	/**
	 * Interprete de texto Markdown.
	 * Puede usar una función externa para interpretar el texto (se asume tiene formato "Markdown"). Si no se ha definido una función externa,
	 * genera un texto HTML básico usando la función interna docblock_parserlocal().
	 *
	 * @param string $text Texto a formatear.
	 * @param bool $remove_tag_p Remueve tag "<p>" incluido usualmente como apertura del texto HTML.
	 * @return string Texto HTML equivalente.
	 */
	public function parserText(string $text, bool $remove_tag_p = false) {

		$text = trim($text);
		if ($text != '') {
			if (is_callable($this->parserTextFunction)) {
				$text = call_user_func($this->parserTextFunction, $text);
			}
			else {
				$text = $this->docblock_parserlocal($text);
			}
			// Protege enlaces
			$text = str_replace('<a href="', '<a target="doclink" href="', $text);
			if ($remove_tag_p) {
				// No lo hace si hay "<p>" en medio del texto, para prevenir tags incompletos.
				if (substr($text, 0, 3) == '<p>' && strpos($text, '<p>', 3) === false) {
					$text = substr($text, 3);
					if (substr($text, -4, 4) == '</p>') { $text = substr($text, 0, -4); }
				}
			}
		}

		return $text;
	}

	/**
	 * Interprete de texto Markdown básico.
	 * Esta función intenta generar un texto HTML compatible con las siguientes guías:
	 *
	 * - Genera un título si la línea empieza con "#", "##", "###", etc. (debe ir seguido de un espacio en blanco).
	 * - Genera listas si la línea inicia con "-" o "*".
	 * - Genera un bloque preformateado si lo empieza y termina con la línea "```".
	 * - Detecta el fin de párrafo si una línea termina en "." o ":" o va seguida de una línea en blanco.
	 *
	 * @param string $text Texto a formatear.
	 * @return string Texto HTML equivalente.
	 */
	private function docblock_parserlocal(string $text) {

		$lineas = explode("\n", $text);
		$text = '';
		$es_pre = false;
		$tags_acum = array();
		$total_lineas = count($lineas);

		for ($k = 0; $k < $total_lineas; $k ++) {

			$linea = $lineas[$k];
			if ($es_pre) {
				// Está capturando texto preformateado. Continua mas adelante evaluación para
				// detectar fin del bloque.
				$text .= htmlspecialchars($linea) . PHP_EOL;
				if ($linea === '```') {
					// Toogle valor
					$es_pre = !$es_pre;
					$text .= $this->docblock_closetags($tags_acum, 'pre') . PHP_EOL;
					continue;
				}
			}

			$linea = trim($linea);
			if ($linea === '') { continue; }

			if (isset($lineas[$k +  1]) && $linea !== '```') {
				// Lee siguiente linea
				while (isset($lineas[$k + 1]) && !in_array(substr($linea, -1, 1), [ '.', ':' ])) {
					$sgte = trim($lineas[$k + 1]);
					if ($sgte == '') {
						// Fin de parrafo (ignora la linea en blanco)
						$k ++;
						break;
					}
					elseif ($sgte == '```' || in_array($sgte[0], [ '-', '*', '>', '#'])) {
						// Empieza item especial
						break;
					}

					$k ++;
					$linea .= ' ' . $sgte;
				}
			}

			// Texto preformateado
			if ($linea === '```') {
				// Toogle valor
				$es_pre = !$es_pre;
				$text .= $this->docblock_opentags($tags_acum, 'pre');
				$linea = '';
			}
			// Listas
			elseif ($linea[0] == '-' || $linea[0] == '*') {
				$text .= $this->docblock_opentags($tags_acum, 'ul');
				$text .= '<li>' . htmlspecialchars(trim(substr($linea, 1))). '</li>';
				$linea = '';
			}
			// Blockquote
			elseif ($linea[0] == '>') {
				$text .= $this->docblock_opentags($tags_acum, 'blockquote');
				$text .= htmlspecialchars(trim(substr($linea, 1)));
				$linea = '';
			}
			// Titulos
			elseif ($linea[0] == '#') {
				$pos = strpos($linea, ' ');
				$tam = substr($linea, 0, $pos);
				if (str_replace('#', '', $tam) == '') {
					// Todos los items previos son "#"
					$hx = 'h' . strlen($tam);
					$text .= "<$hx>" . htmlspecialchars(trim(substr($linea, $pos + 1))) . "</$hx>" . PHP_EOL;
					$linea = '';
				}
			}
			else {
				$text .= $this->docblock_closetags($tags_acum, '');
			}

			if ($linea != '') {
				$text .= '<p>' . htmlspecialchars($linea) . '</p>' . PHP_EOL;
			}
		}

		// Valida si terminó con un tabulado abierto
		$text .= $this->docblock_closetags($tags_acum, '');

		return $text;
	}

	/**
	 * Soporte para docblock_parserlocal: Realiza apertura de tags HTML.
	 * Si se ejecutan aperturas consecutivas del mismo tag, solo aplica la primera.
	 *
	 * @param array $tags_acum Arreglo editable con los tags abiertos (ul, blockquote).
	 * @param string $tag Tag a evaluar.
	 * @return string texto HTML con los tags abiertos (si alguno). Ej: "<ul>".
	 */
	private function docblock_opentags(array &$tags_acum, string $tag) {

		$acum = '';
		$i = count($tags_acum) - 1;
		if ($i < 0 || (isset($tags_acum[$i]) && $tags_acum[$i] != $tag)) {
			while ($i >= 0 && isset($tags_acum[$i]) && $tags_acum[$i] != $tag) {
				// Cierra todo hasta encontrar uno igual o llegar a ceros
				$acum .= '</' . $tags_acum[$i] . '>';
				unset($tags_acum[$i]);
				$i --;
			}
			$i++;
			$tags_acum[$i] = $tag;
			$acum .= '<' . $tag . '>';
		}

		return $acum;
	}

	/**
	 * Soporte para docblock_parserlocal: Realiza cierre de tags HTML al evaluar texto.
	 *
	 * @param array $tags_acum Arreglo editable con los tags abiertos (ul, blockquote).
	 * @param string $tag Tag a evaluar. En blanco cierra todo lo abierto.
	 * @return string texto HTML con los tags cerrados (si alguno). Ej: "</ul>".
	 */
	private function docblock_closetags(array &$tags_acum, string $tag) {

		$acum = '';
		$i = count($tags_acum) - 1;
		while ($i >= 0 && isset($tags_acum[$i])) {
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
<?php
/**
 * Librería de funciones requeridas para las aplicaciones nativas de miFrame.
 * Aviso: Esta copia corresponde a una versión reducida del archivo original, incluido en micode-manager.
 *
 * @author John Mejia
 * @since Abril 2022
 */

/**
 * Crea un path uniforme.
 * Evalua ".." y estandariza el separador de segmentos, usando DIRECTORY_SEPARATOR.
 * Basado en ejemplo encontrado en https://www.php.net/manual/en/dir.constants.php#114579
 * (Builds a file path with the appropriate directory separator).
 * Aviso: Por precaución es mejor no usar realpath(), especialmente en entornos Windows muy controlados.
 * Referencia: https://www.php.net/manual/en/function.realpath
 * > The running script must have executable permissions on all directories in the hierarchy, otherwise realpath() will return false.
 *
 * @param string $segments Segmentos del path a construir.
 * @return string Path
 */
function miframe_path(...$segments) {

    $path = join(DIRECTORY_SEPARATOR, $segments);
	// Confirma en caso que algun elemento de $segments contenga uncaracter errado
	if (DIRECTORY_SEPARATOR != '/') {
		$path = str_replace('/', DIRECTORY_SEPARATOR, $path);
	}
	// Remueve ".."
	if (strpos($path, '..') !== false) {
		$arreglo = explode(DIRECTORY_SEPARATOR, $path);
		$total = count($arreglo);
		for ($i = 0; $i < $total; $i++) {
			if ($arreglo[$i] == '..') {
				$arreglo[$i] = '';
				while ($arreglo[$i - 1] == '' && $i >= 0) { $i--; }
				if ($i > 0) {
					$arreglo[$i - 1] = '';
				}
			}
		}
		// Remueve elementos en blanco
		$path = implode(DIRECTORY_SEPARATOR, array_filter($arreglo));
	}

	return $path;
}

/**
 * Retorna la fecha de creación o modificación de un archivo o directorio, aquella que sea más antigua.
 * No siempre usa la fecha de creación porque puede pasar que al copiar un directorio o moverlo, la fecha de
 * creación sea más reciente que la de modificación.
 *
 * @param string $filename Nombre del archivo/directorio a validar.
 * @return string Fecha recuperada.
 */
function miframe_filecreationdate(string $filename) {

	$retornar = '';

	if (file_exists($filename)) {
		$fechamod = filemtime($filename);
		$fechacrea = filemtime($filename);
		if ($fechacrea > $fechamod) { $fechacrea = $fechamod; }
		$retornar = date('Y/m/d', $fechacrea);
	}

	return $retornar;
}

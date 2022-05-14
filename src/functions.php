<?php
/**
 * Librería de funciones requeridas para las aplicaciones nativas de miFrame.
 * Aviso: Esta copia corresponde a una versión reducida del archivo original, incluido en micode-manager.
 *
 * @author John Mejia
 * @since Abril 2022
 */

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

/**
 * Retorna la extensión dada para el archivo indicado.
 *
 * @link https://stackoverflow.com/questions/173868/how-to-get-a-files-extension-in-php
 * @param string $filename Nombre del archivo a validar.
 * @return string extensión del archivo, en minúsculas e incluye el ".".
 */
function miframe_extension(string $filename) {

	$extension = pathinfo($filename, PATHINFO_EXTENSION);
	if ($extension != '') { $extension = '.' . strtolower($extension); }

	return $extension;
}

<?php
/**
 * Script para probar implementación de clase DocSimple.php
 *
 * @author John Mejía
 * @since Abril 2022
 */

include __DIR__ . '/../src/docsimple.php';
include __DIR__ . '/../src/functions.php';

$files = array(
	0 => __DIR__ . '/../src/docsimple.php',
	1 => __DIR__ . '/../src/functions.php',
	2 => __FILE__
);

$selecto = 0;
if (isset($_REQUEST['file']) && isset($files[$_REQUEST['file']])) {
	$selecto = $_REQUEST['file'];
}

$doc = new \miFrame\Utils\DocSimple();

$ejemplos = '';
foreach ($files as $k => $file) {
	if ($ejemplos != '') { $ejemplos .= ' | '; }
	if ($k == $selecto) {
		$documento = $doc->getDocumentationHTML($file, true);
		$ejemplos .= "<b>" . basename($file) . "</b> ";
	}
	else {
		$ejemplos .= "<a href=\"?file=$k\">" . basename($file) . "</a>";
	}
}

?>
<!DOCTYPE html>
<html>
	<head>
		<title>Test DocSimple</title>
	</head>
<body>

<style>
body {
	font-family: "Segoe UI",Helvetica,Arial,sans-serif;
	font-size: 14px;
	line-height: 1.5;
	word-wrap: break-word;
	/* padding:0 20px; */
	}
h1 {
    padding-bottom: .3em;
    font-size: 2em;
    border-bottom: 1px solid hsla(210,18%,87%,1);
	}
h2 {
	margin-top: 24px;
}
h1, h2 {
	margin-bottom: 16px;
	font-weight: 600;
	line-height: 1.25;
}
code {
	background: rgba(175,184,193,0.2);
	font-size: 14px;
	padding:0 5px;
	font-family: Consolas;
}
pre.code {
	background: rgba(175,184,193,0.2);
	border: 1px solid #d0d7de;
	padding:16px;
}
</style>

<h1>Test DocSimple</h1>

<p>Uso:</p`>
<pre class="code">
	$doc = new \miFrame\DocSimple();
	$documento = $doc->getDocumentationHTML($file, true);
</pre>
<p>
	Explorar: <?= $ejemplos ?>
</p>

<?= $documento ?>

</body>
</html>
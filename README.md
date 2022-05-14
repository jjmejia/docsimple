# miframe-docsimple

Librería PHP para generar documentación a partir de los bloques de comentarios incluidos en el código.

Funciona con base en el modelo Javadoc adaptado según se describe en [phpDocumentor](https://docs.phpdoc.org/guide/guides/docblocks.html), donde se documenta en bloques de comentario dentro del script, las clases y/o las funciones contenidas en el mismo.

Algunos tags a tener en cuenta:

* author: Nombre del autor del elemento asociado.
* link: Relación entre el elemento asociado y una página web (referencias).
* param: (Sólo para funciones, métodos) Cada argumento de una función o método, uno por tag.
* return: (Sólo para funciones, métodos) Valor retornado por una función o método.
* since: Indica en qué versión el elemento asociado estuvo disponible.
* todo: Actividades o mejoras por realizar al elemento asociado.
* uses: Indica referencias a otros elementos.
* version: Versión actual del elemento estructural (a nivel de script más que de funciones o métodos).

Uso:

    $doc = new \miFrame\DocSimple();
    $documento = $doc->getDocumentationHTML($filename, true);

## Class miFrame\DocSimple

Las siguientes propiedades públicas pueden ser usadas:

* $tags: array. Atributos para evaluar documentación. Se predefine en el __construct() para soportar el modelo PHP Javadoc.
* $debug: boolean. TRUE para incluir mensajes de depuración.
* $evalRequiredItems: boolean. TRUE para evaluar elementos mínimos requeridos.
* $usesFunction: Función a usar al mostrar elemento "@uses" en $this->getDocumentationHTML(). Retorna texto HTML. Ej:
      function ($modulo, $infomodulo) { ... return $html; }
* $parserTextFunction: Función a usar para interpretar el texto (asumiendo formato Markdown). Retorna texto HTML. Ej:
      function (text) { ... return $html; }

Métodos relevantes:

* evalCodeBlock (private function) -- Evalúa bloque de código encontrado entre bloques de documentación.
* evalDocBlock (private function) -- Evalúa un bloque de código sanitizado previamente y recupera los atributos de documentación.
* evalHTMLDoc (private function) -- Procesa contenido y genera texto HTML equivalente.
* filename -- Retorna el nombre del último archivo procesado.
* getDocumentation -- Recupera los bloques de documentación del archivo $filename.
* getDocumentationHTML -- Retorna la documentación encontrada en formato HTML.
* getSummary -- Descripción básica del elemento asociado.
* parserLink -- Genera enlace para navegación de funciones en documentación HTML.
* parserText -- Interprete de texto Markdown.

## Demo

El script `tests/docsimple-demo.php` contiene una demostración completa de la funcionalidad de esta clase.

## Importante!

Esta librería forma parte de los módulos PHP incluidos en [micode-manager](https://github.com/jjmejia/micode-manager).

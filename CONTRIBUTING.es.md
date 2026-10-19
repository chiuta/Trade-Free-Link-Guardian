# Contribuir a Trade-Free Link Guardian

Gracias por plantearte una contribución. Algunas cosas útiles que conviene saber antes de empezar.

## La estructura de archivo único — deliberada, no accidental

Todo el código del plugin está en un único archivo PHP, `trade-free-link-guardian.php`. No es una elección pasajera, es la filosofía del proyecto: «sin fronteras» — sin paso de compilación, sin dependencias externas que instalar, funcional con solo copiar un archivo. Forma parte de un portafolio más amplio de aplicaciones de archivo único construidas sobre el mismo principio.

Si vienes con experiencia moderna en desarrollo de WordPress (espacios de nombres, autocarga, un archivo por clase), la estructura puede parecer inusual. Es intencionada, no una deuda técnica que resolver con un gran refactor. Una contribución que proponga dividir el archivo en varios no se aceptará solo por ese motivo — pero una contribución que mejore algo *dentro* de esta estructura (una función nueva, una corrección, una prueba adicional) es bienvenida.

## Orientación rápida en el archivo

Al principio del archivo hay un índice («TABLE OF CONTENTS») — cada clase tiene un texto de búsqueda exacto (por ejemplo `SECTION: TFLG_Checker`) que puedes buscar (Ctrl+F / Cmd+F) en cualquier editor para ir directamente a ella. Se prefiere a los números de línea, que quedarían obsoletos con cualquier cambio.

Las trece clases, en breve:
- `TFLG_Util` — funciones auxiliares sin estado, usadas en todas partes
- `TFLG_DB` — el esquema y todas las consultas directas a la base de datos
- `TFLG_Scanner` — extrae enlaces del contenido
- `TFLG_Checker` — hace la petición HTTP que decide si un enlace está vivo
- `TFLG_Fixer` — las seis correcciones deterministas, aplicar/deshacer
- `TFLG_List_Table` — la tabla de la pantalla Links (solo admin)
- `TFLG_Admin` — el HTML de cada página de administración, el menú, CSS/JS
- `TFLG_Ajax` — cada punto de acceso `wp_ajax_`
- `TFLG_Network_Admin` — la vista a nivel de red, solo multisitio
- `TFLG_Editor_Check` — el aviso al guardar una entrada
- `TFLG_404_Monitor` — el registro pasivo de errores 404
- `TFLG_Cron` — el análisis programado opcional
- `TFLG_Plugin` — activación, desactivación, desinstalación, el constructor que lo conecta todo

## Pruebas — obligatorias, no opcionales

Cada función o corrección nueva debe ir acompañada de pruebas. Las pruebas existentes no se ejecutan con un PHPUnit clásico instalado como dependencia — son scripts PHP autónomos que extraen el código real del archivo principal (contando llaves, sin suponer el texto) y lo ejecutan aislado, con stubs mínimos para las funciones de WordPress necesarias. Para la lógica JavaScript, las pruebas reales se ejecutan con Node.js — las que tocan la manipulación del DOM (por ejemplo la ordenación en Book View) usan `jsdom` para un entorno DOM real, no una simulación superficial.

Una prueba que solo confirma lo que ya esperas que ocurra no ayuda mucho. Las pruebas más útiles de este proyecto son las que comprueban exactamente el caso límite fácil de errar — por ejemplo, que una función de vista previa nunca escriba en la base de datos, o que un índice se mantenga sincronizado con el contenido real tras una reordenación.

## Seguridad — verificación directa, no suposición

Toda consulta SQL nueva debe usar `$wpdb->prepare()` con parámetros reales, no interpolación de cadenas. Toda acción que cambie el estado (AJAX, formulario) debe comprobar tanto la capacidad (`current_user_can`) como un nonce. Todo contenido mostrado que pueda proceder de una fuente menos fiable que un administrador — el título de una página externa, una cabecera HTTP enviada por un visitante — debe escaparse al mostrarse, no solo sanearse al entrar.

## Sin telemetría, sin excepciones

Nada de este plugin envía datos a servicios externos sin una acción explícita del usuario. Una contribución que añada cualquier forma de informe automático a un servidor externo, por bienintencionada que sea, no se aceptará.

## Cómo enviar una contribución

El proyecto se publicará en WordPress.org, con el flujo SVN estándar de la plataforma. Para contribuciones de código, lo más sencillo es describir con claridad el cambio propuesto y su motivo, junto con las pruebas correspondientes, directamente al autor (véanse los datos de contacto en la cabecera del plugin o en readme.txt).

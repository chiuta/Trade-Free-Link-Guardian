# Contribuer à Trade-Free Link Guardian

Merci d'envisager une contribution. Quelques points utiles à connaître avant de commencer.

## La structure en fichier unique — délibérée, pas accidentelle

Tout le code du plugin se trouve dans un seul fichier PHP, `trade-free-link-guardian.php`. Ce n'est pas un choix de circonstance, c'est la philosophie du projet : « sans frontières » — pas d'étape de build, pas de dépendances externes à installer, fonctionnel par simple copie d'un seul fichier. Cela fait partie d'un portefeuille plus large d'applications en fichier unique construites sur le même principe.

Si vous venez avec une expérience moderne du développement WordPress (espaces de noms, chargement automatique, un fichier par classe), la structure peut sembler inhabituelle. Elle est voulue, ce n'est pas une dette technique à résoudre par un grand refactoring. Une contribution qui propose de scinder le fichier en plusieurs ne sera pas acceptée pour ce seul motif — mais une contribution qui améliore quelque chose *à l'intérieur* de cette structure (une nouvelle fonction, une correction, un test supplémentaire) est la bienvenue.

## Orientation rapide dans le fichier

Au début du fichier se trouve une table des matières (« TABLE OF CONTENTS ») — chaque classe a une chaîne de recherche exacte (par exemple `SECTION: TFLG_Checker`) que vous pouvez rechercher (Ctrl+F / Cmd+F) dans n'importe quel éditeur pour y accéder directement. À préférer aux numéros de ligne, qui deviendraient obsolètes à la moindre modification.

Les treize classes, en bref :
- `TFLG_Util` — fonctions utilitaires sans état, utilisées partout
- `TFLG_DB` — le schéma et toutes les requêtes directes vers la base de données
- `TFLG_Scanner` — extrait les liens du contenu
- `TFLG_Checker` — effectue la requête HTTP qui décide si un lien est vivant
- `TFLG_Fixer` — les six corrections déterministes, application/annulation
- `TFLG_List_Table` — le tableau de l'écran Links (admin uniquement)
- `TFLG_Admin` — le HTML de chaque page d'administration, le menu, CSS/JS
- `TFLG_Ajax` — chaque point d'accès `wp_ajax_`
- `TFLG_Network_Admin` — la vue au niveau du réseau, multisite uniquement
- `TFLG_Editor_Check` — l'avertissement lors de l'enregistrement d'un article
- `TFLG_404_Monitor` — le journal passif des erreurs 404
- `TFLG_Cron` — l'analyse planifiée facultative
- `TFLG_Plugin` — activation, désactivation, désinstallation, le constructeur qui relie le tout

## Tests — obligatoires, pas facultatifs

Chaque nouvelle fonction ou correction doit s'accompagner de tests. Les tests existants ne passent pas par un PHPUnit classique installé comme dépendance — ce sont des scripts PHP autonomes qui extraient le code réel du fichier principal (en comptant les accolades, sans supposer le texte) et l'exécutent isolément, avec des stubs minimaux pour les fonctions WordPress nécessaires. Pour la logique JavaScript, les vrais tests s'exécutent avec Node.js — ceux qui touchent à la manipulation du DOM (par exemple le tri dans Book View) utilisent `jsdom` pour un véritable environnement DOM, et non une simulation superficielle.

Un test qui se contente de confirmer ce que vous attendez déjà n'aide guère. Les tests les plus utiles de ce projet sont ceux qui vérifient précisément le cas limite facile à rater — par exemple, qu'une fonction d'aperçu n'écrit jamais dans la base de données, ou qu'une table des matières reste synchronisée avec le contenu réel après une réorganisation.

## Sécurité — vérification directe, pas supposition

Toute nouvelle requête SQL doit utiliser `$wpdb->prepare()` avec de vrais paramètres, et non une interpolation de chaînes. Toute action qui modifie l'état (AJAX, formulaire) doit vérifier à la fois la capacité (`current_user_can`) et un nonce. Tout contenu affiché qui pourrait provenir d'une source moins fiable qu'un administrateur — le titre d'une page externe, un en-tête HTTP envoyé par un visiteur — doit être échappé à l'affichage, et pas seulement nettoyé à l'entrée.

## Pas de télémétrie, sans exception

Rien dans ce plugin n'envoie de données à des services externes sans une action explicite de l'utilisateur. Une contribution qui ajoute toute forme de rapport automatique vers un serveur externe, aussi bien intentionnée soit-elle, ne sera pas acceptée.

## Comment soumettre une contribution

Le projet doit être publié sur WordPress.org, avec le flux SVN standard de la plateforme. Pour les contributions de code, le plus simple est de décrire clairement la modification proposée et sa raison, avec les tests correspondants, directement à l'auteur (voir les coordonnées dans l'en-tête du plugin ou dans readme.txt).

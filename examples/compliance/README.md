# Matérialiser une requête d’habilitation

Les justificatifs actifs et les exigences sont stockés dans des tables de référence.
Une requête SQL définit les habilitations d’un sujet : chaque groupe d’exigences
requiert au moins un justificatif d’un des types proposés dans ce groupe. `base` et
`base_v2` sont des définitions métier immuables qui coexistent dans le même catalogue.

## Exécuter l’exemple

Depuis la racine du dépôt, lancer le PostgreSQL du [guide de développement](../../docs/development.md#setup).
Puis, avec PHP 8.5, `pdo_pgsql` et Composer :

```bash
cd examples/compliance
composer install
export ULTRA_TEST_POSTGRES_DSN='pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test'
php bin/demo.php
composer test
```

Depuis la racine, on peut aussi lancer `bin/phpunit -c examples/compliance/phpunit.xml.dist`.
Les tests et la démo créent puis suppriment leur propre schéma PostgreSQL.

## Assemblage Symfony

Le [kernel Symfony](src/Kernel.php) compile le conteneur avec `UltraBundle`. Le
[runtime](src/Runtime.php) lui fournit une connexion DBAL commune aux services
d’écriture, de projection et de rafraîchissement. La démo et les tests démarrent
ce même kernel sur leur connexion de test.

## Parcours

1. `define`, `provide` et `withdraw` envoient des commandes typées au médiateur. Les
   chargeurs générés par EventSourcedState reconstruisent l’état nécessaire à chaque décision
   depuis le flux de l’habilitation ou du justificatif dans le compte. L’état de
   l’habilitation protège sa définition immuable. L’état du justificatif distingue
   un identifiant inédit, un justificatif fourni et un justificatif retiré. L’Unit
   of Work persiste les événements des commandes acceptées dans l’EventStore.
   Voir les [décisions avec état](../../docs/message-handling/stateful.md).
2. `Runtime::project()` fait tourner le `ProjectionCoordinator` à la demande sur les
   événements persistés. [SourceProjection](src/SourceProjection.php) est partitionnée par
   compte et possède les Mapped Tables des justificatifs et des exigences. Chaque lot
   écrit ces tables de référence, enregistre les périmètres invalidés et avance le
   checkpoint dans une seule transaction.
3. `Runtime::refresh($account, $maxSteps)` relève les marqueurs du compte. La [requête de référence](src/ReferenceQueries.php)
   calcule les lignes attendues. Le [worker](../../docs/coordination/refresh.md) publie le résultat et acquitte
   les marqueurs dans sa propre transaction.

Le coordinateur suit les lots d’événements à projeter. Le worker de rafraîchissement
suit les périmètres à recalculer à partir des données de référence. Chacun utilise
son propre registre de travail en attente.

| Changement | Périmètre invalidé | Recalcul |
| --- | --- | --- |
| Fournir ou retirer un justificatif | Compte et sujet | Remplacer toutes les habilitations de ce sujet, y compris par un ensemble vide |
| Définir une habilitation | Compte et habilitation | Parcourir les sujets par lots et écrire les résultats de cette seule habilitation |

Le recalcul d’un sujet couvre aussi les habilitations en construction. Une modification
arrivée derrière le curseur d’un scan est donc couverte par le marqueur du sujet. Pour
chaque scan, le curseur et le compteur d’invalidations de départ sont persistés avec les
lignes du lot. Le dernier lot acquitte ce compteur et publie l’état prêt. Une invalidation
ultérieure de l’habilitation déclenche un autre passage depuis le début.

Le sujet est identifié par son compte et son identifiant local. Tout type de document
peut être référencé par une ou plusieurs habilitations. Un justificatif peut être retiré
et son identifiant reste réservé dans le flux d’événements. La validité dans le temps est
hors du périmètre de l’exemple. Un retrait illustre le changement de l’état courant.

## Lectures

`$runtime->queries->subjects($account, $qualification)` et
`$runtime->queries->qualifications($account, $subject)` interrogent les résultats
matérialisés, avec un index pour chaque sens. Une habilitation inconnue ou en construction
est signalée par `subjects()`. `qualifications()` liste les habilitations prêtes uniquement.
Une habilitation prête peut avoir du retard sur les dernières écritures pendant leur
traitement. `$runtime->reference` expose les deux requêtes directement sur les tables de
référence pour comparer leurs résultats ou lire l’état projeté sans attendre le rafraîchissement.

La progression d’un scan reprend après redémarrage. Une reconstruction complète
est une opération hors ligne : arrêter les deux traitements, vider les résultats,
les curseurs et les compteurs du rafraîchissement, puis rejouer la projection source
avec `ProjectionRunner::replayFromBeginning()`. Ce rejeu recrée les marqueurs à
dépiler par le worker. Le polling ordinaire de `Runtime::project()` attend que
l’EventStore signale l’activité de nouveaux appends.

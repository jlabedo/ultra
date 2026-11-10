# Symfony, event sourcing et projection Mapped Table

Cette application Symfony suit des commandes : création, expédition, puis lecture
d'une projection PostgreSQL. Elle montre les deux frontières transactionnelles
et le câblage des services dans [services.yaml](config/services.yaml).

## Installation et exécution

Prérequis : PHP 8.5 avec `pdo_pgsql`, Composer et PostgreSQL 18 (la version utilisée
par le dépôt). Depuis la racine du dépôt, `docker compose up -d` démarre la base
locale décrite dans le [guide de développement](../../docs/development.md#setup).

```bash
cd examples/symfony-event-sourcing
composer install
export DATABASE_URL='pdo-pgsql://ultra:ultra@127.0.0.1:55432/ultra_test'
php bin/console app:orders schema
php bin/console app:orders place order-1 Ada
php bin/console app:orders ship order-1
php bin/console app:orders list
php bin/console app:orders project
php bin/console app:orders list
```

Utiliser une base dédiée ou un schéma PostgreSQL vide : `schema` crée les tables
une seule fois et échoue si celles de l'event store existent déjà. La création est
transactionnelle. `DATABASE_URL` a pour valeur par défaut la connexion locale ci-dessus ;
l'application n'utilise pas de fichier `.env`.

Chaque commande affiche les lignes de `order_summary`. Avant `project`, la liste
est vide ; après, elle contient `order-1`, `Ada`, `shipped`. Les invocations sont
des processus indépendants : événements, activité et checkpoints sont persistés.
Une deuxième création ou expédition du même identifiant est refusée.

`project` effectue un passage borné du coordinateur ; le relancer si nécessaire
pour un historique important. Pour traiter les arrivées continuellement :

```bash
php bin/console ultra:projection:run -v
```

Le worker interroge l'activité persistée. Le choix explicite d'un activateur en
mémoire suffit ici car aucun transport de réveil n'est nécessaire au polling.
Les commandes passent par `MediatorInterface` et ses handlers de décision.
L'activateur en mémoire évite de déclencher la projection pendant le flush des
commandes ; le worker la traite séparément.

Pour reconstruire la projection depuis les événements, arrêter les workers puis :

```bash
php bin/console app:orders rebuild
```

La reconstruction supprime les lignes et checkpoints de cette projection puis
rejoue l'historique par lots. Elle s'utilise hors ligne, sans commandes ni workers
concurrents ; elle n'est pas une transaction globale sur tout l'historique.

## Parcours et responsabilités

[Orders](src/Orders.php) déclare deux handlers `#[DecisionHandler]` : création
et expédition. Leur paramètre [OrderState](src/State/OrderState.php) est un état
`#[EventSourcedEntity(tags: ['order'])]`, reconstruit par les loaders compilés
d'EventSourcedState. L'identifiant est lié à `orderId` sur chaque commande.
Les handlers retournent des événements métier ; l'aspect de décision prend en
charge leur normalisation et leur ajout avec protection optimiste. La projection
n'intervient pas dans les décisions. Voir les contrats
[EventSourcedState](../../docs/event-sourcing/states.md) et
[des décisions](../../docs/message-handling/decisions.md).

`UltraBundle` découvre les extensions des packages installés. L'extension UnitOfWork
décore l'event store et entoure les handlers compilés d'un travail transactionnel.
Un appel englobant à `UnitOfWorkRunner` permet de regrouper plusieurs commandes :

```php
$work->run(function () use ($mediator): void {
    $mediator->send(new \App\Command\PlaceOrder('order-2', 'Grace'));
    $mediator->send(new \App\Command\ShipOrder('order-2'));
});
```

Les appels imbriqués partagent les événements en attente. Le flush persiste les
événements et leur activité de projection avec le même `DoctrineTransactionManager`
et la même connexion DBAL, fournie par DoctrineBundle et configurée dans
[doctrine.yaml](config/packages/doctrine.yaml). Voir les contrats de
[l'Unit of Work](../../docs/transactions/README.md) et de son
[intégration Symfony](../../docs/transactions/symfony.md).

[OrderSummary](src/Projection/OrderSummary.php) construit son `MappedRowSet` dans
son constructeur à partir de la connexion DBAL. Plusieurs tables peuvent être
construites de la même façon ; elles ne deviennent pas des services Symfony.

Les handlers traitent `OrderPlaced` et `OrderShipped` dans l'ordre de chaque commande
grâce à `partitionBy: 'order'`. Le hook `#[ProjectionFlush]` appelle `flush()`, puis
`clear()` dans un `finally`. Les écritures et le checkpoint partagent la même
transaction. Le resetter vide la table par réconciliation puis appelle ce même
flush. Aucun décorateur transactionnel applicatif n'est nécessaire.

Si un handler échoue avant le hook de flush, son buffer n'est pas nettoyé par ce
hook. Le worker propage l'erreur et s'arrête ; le redémarrer recrée la projection.
Un appelant qui réessaie dans le même processus doit recréer la projection ou
nettoyer explicitement ses buffers. Voir le contrat des
[modèles de lecture bufferisés](../../docs/projections/README.md#buffered-read-models)
et celui de [Mapped Table](../../docs/stored-state/mapped-table.md).

[Schema](src/Schema.php) assemble les DDL fournis par les packages et la table de
lecture. L'event store utilise QueryStreams, sans extension `hstore`. L'exemple
utilise les packages locaux via le dépôt Composer `../../src/*/*` ; pour installer
Ultra hors du monorepo, suivre le [guide d'installation](../../docs/development.md#installing-in-an-application).

## Vérification

```bash
composer test
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup
```

Les tests démarrent le vrai kernel Symfony avec PostgreSQL. Chaque test crée un
schéma isolé et le supprime à la fin ; le compte doit disposer de ce droit.
Ils couvrent le travail imbriqué et ses événements en attente, la persistance entre
kernels, le rattrapage par lots, la reprise sans double application, la reconstruction,
les transitions refusées depuis l'état persisté, l'abandon du travail applicatif, le rollback des événements si l'activité échoue,
et le rollback des lignes si le checkpoint échoue, avec réutilisation du worker.
Ils échouent si la base est indisponible.

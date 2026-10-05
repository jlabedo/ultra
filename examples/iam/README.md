# IAM avec StateStore

Cet exemple construit un contexte d'autorisation RBAC multi-tenant avec les
composants existants d'Ultra. Les commandes passent par `StateStore`,
`MappedRowSet` et `TransactionalUnitOfWorkRunner`. La vérification d'accès utilise
une seule requête Postgres, sans hydratation d'agrégats ni cache de permissions.
Il est indépendant de Symfony et de l'event sourcing.

## Modèle et politique

| Concept | Responsabilité |
| --- | --- |
| Tenant | Frontière d'administration et d'isolation |
| Sujet | Identifiant stable d'une identité humaine ou technique déjà authentifiée |
| Groupe | Ensemble de sujets, sans imbrication |
| Permission | Action connue de l'application, par exemple `documents:edit` |
| Rôle | Ensemble de permissions remplaçable, avec activation/désactivation |
| Attribution | Rôle accordé à un sujet ou groupe, avec portée et expiration optionnelle |
| Portée | Toutes les ressources du tenant, ou une ressource exacte `(type, id)` |
| Audit | Acteur, opération, cible, nouvel état et instant de chaque commande validée |

Les permissions sont un catalogue typé dans `Domain/Permission.php`. Ajouter une
permission fait partie d'un changement applicatif ; les administrateurs composent
les rôles à partir de ce catalogue. Aucun utilisateur ne reçoit de permission
directe : les droits viennent de l'union de ses rôles et de ceux de ses groupes.
Une identité JWT, une clé API ou un login résolus vers le même sujet obtiennent
les mêmes droits. Les rôles éventuellement présents dans un token ne sont pas
utilisés comme source d'autorisation.

Une requête est refusée sans attribution correspondante. Le sujet doit être
actif ; un droit hérité exige aussi un groupe et une adhésion actifs. Le rôle et
l'attribution doivent être actifs, et `expiresAt > now` si une date est définie.
L'expiration est un timestamp UTC en secondes, avec refus à la seconde exacte.
La portée tenant couvre toutes ses ressources ; la portée ressource exige le
même type et le même identifiant. Il n'y a ni wildcard de permission, ni refus
explicite, ni hiérarchie de rôles, ni langage ABAC dans ce premier contexte.
Ces extensions sont des choix de domaine ultérieurs, pas des lacunes prouvées du
framework. L'exemple reprend le modèle
[rôles/permissions d'Auth0](https://auth0.com/docs/manage-users/access-control/authorization-policies)
et les principes OWASP de
[refus par défaut et contrôle sur chaque requête](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

## Écriture

`Administration` est la frontière de commandes. Toutes ses méthodes exigent
`iam:manage` à la portée **tenant** pour l'acteur. Un rôle administrateur limité à
une ressource ne permet pas d'administrer le tenant. Cet administrateur dispose
de tous les droits de gestion IAM dans son tenant ; la délégation restreinte de
permissions n'est pas implémentée. Il ne possède pas automatiquement les droits
métier de lecture ou d'édition de documents.

Les identifiants composites incluent toujours le tenant. L'adhésion à un groupe
et les attributions valident leurs références via `StateStore::observeExists()`,
qui protège leur présence sans charger leurs colonnes ni les collections. Une
modification concurrente du contenu d'un rôle référencé reste permise. Les états
modifiés sont observés entièrement avec `observe()` pour éviter les mises à jour
perdues. Les
permissions d'un rôle sont une `StateCollection` relationnelle ; les doublons
sont éliminés et remplacer le rôle réconcilie sa collection.

La révocation désactive l'attribution, et le retrait d'un groupe désactive
l'adhésion. Ces transitions métier préservent l'identité et permettent une
réactivation. Elles ne demandent pas de suppression physique d'un State.
Le journal conserve aussi l'état écrit, par exemple les permissions d'un rôle
ou l'expiration d'une attribution. Une erreur d'audit annule la commande entière.
Les refus peuvent être journalisés par la couche HTTP/observabilité ; ils ne sont
pas inscrits dans le journal transactionnel des changements validés.

`Provisioning::createTenant()` constitue un port distinct, réservé au
provisionnement de confiance. Il crée le premier sujet, le rôle administrateur,
son attribution et l'audit en une seule transaction. Il refuse un tenant déjà
existant et ne doit pas être exposé comme une commande publique authentifiée.
Une désactivation ou révocation du dernier administrateur est autorisée : cet
exemple ne définit pas de politique de récupération ni de compte de secours.

## Lecture et intégration applicative

`Authorization::allows()` est le point de décision ; `require()` lève
`AccessDenied` pour un refus. La requête joint les attributions et permissions
courantes à partir du sujet et des groupes. Les clés commencent par le tenant et
le principal ; un index partiel couvre les adhésions actives depuis un sujet.
La vérification effectue un seul aller-retour à la base et ne charge ni rôle ni
collection PHP. Ce choix vise un chemin de lecture court ; aucun débit ou temps
de réponse n'est revendiqué sans benchmark représentatif.

Le contrôleur vérifie l'identité en amont puis fournit son identifiant stable.
La ressource et son tenant doivent provenir du stockage applicatif. Exemple de
point de contrôle dans un contrôleur de lecture :

```php
$document = $documents->get($requestedDocumentId);
$subject = new LocalId($document->tenantId, $verifiedIdentity->subjectId);
$authorization->require(
    $subject,
    Permission::ReadDocument,
    Scope::resource('document', $document->id),
);
return $document;
```

L'application doit appliquer ce contrôle sur chaque chemin d'accès et traduire
`AccessDenied` en HTTP 403. Un identifiant de sujet fourni dans le corps de la
requête ne doit pas devenir l'acteur. Le namespace d'identité externe (émetteur,
subject) doit être résolu vers l'identifiant interne avant l'entrée dans IAM.

La lecture observe les données validées sur le primaire, à `READ COMMITTED` ;
elles n'utilisent ni replica retardé ni cache. Une révocation est visible à la
prochaine vérification après commit. Une lecture déjà autorisée n'est pas
rétroactivement annulée. Pour une écriture métier qui exige de résister à une
révocation concurrente, il faut participer au protocole de garde et de relecture
ci-dessous sur la même transaction, comme le fait l'administration IAM.

## Concurrence : une garde par tenant

Les commandes décident avant l'ouverture de la transaction, conformément au
runner Ultra. `Runtime` compile un query guard nommé `iam_authorization`, puis
l'injecte dans `Administration`. La commande observe son autorisation via
`QueryGuard::observe($actor->tenantId, ...)`, avant de préparer ses écritures.
Au flush, les gardes sont acquises, l'autorisation est relue à l'heure courante,
puis les attentes optimistes et les écritures sont appliquées. Une révocation ou expiration entre décision et flush
invalide la commande avec `ConcurrencyException`. Aucun retry automatique n'est
effectué : le transport peut recommencer la commande avec une nouvelle décision.

Cette relecture est une politique de révocation choisie pour l'administration
IAM, pas une obligation pour toute commande. Une politique qui autorise les
commandes déjà acceptées à terminer serait possible. Ici, un administrateur
autorisé à préparer une attribution ne doit pas pouvoir la valider après que
sa propre autorisation a été révoquée : il pourrait sinon recréer un accès via
un autre sujet. La garde et la relecture ordonnent cette attribution par rapport
à la révocation. Une simple deuxième vérification sans garde laisserait une
nouvelle fenêtre de concurrence.

La définition couvre `active` sur les sujets, groupes et appartenances,
`active` et `expiresAt` sur les attributions, ainsi que les rôles :

```php
$definition
    ->watch(RoleState::class, scopeBy: 'id.tenantId')
    ->whenPropertiesChange('active', 'permissions');
```

Les readers demandent une garde partagée. StateStore déduit automatiquement les
gardes exclusives des plans de mutation, y compris pour les permissions d'une
collection vidée, le provisioning et les writers utilisant directement
`Runtime::$states`. Les requêtes initiales qui ignoreraient une mutation locale
déjà préparée sont rejetées. Les gardes restent **par tenant** : les mutations
affectant l'autorisation d'un même tenant se sérialisent, les readers peuvent
partager leur garde et les autres tenants restent indépendants. Les vérifications
ordinaires via `Authorization::allows()` restent des lectures SQL sans garde. Le
`Ultra\UnitOfWork\DoctrineTransactionManager` possède sa transaction et est
configuré ici à `READ COMMITTED`, afin que
la relecture voie les révocations validées pendant l'attente de la garde. Les
commandes ne peuvent pas être imbriquées dans un autre runner : une requête SQL
ne verrait pas les révocations encore bufferisées dans la même unité de travail.

Des écritures SQL directes ou via des row sets indépendants de ce registre,
des dépendances omises dans la définition, ou des snapshots répétés hors de ce
protocole invalident cette garantie. Le port bas niveau
`Runtime::$states` est destiné à la composition et aux tests ; la frontière
applicative normale reste `Administration`.

## Ce que l'expérience démontre et ce qui manque

Les primitives existantes suffisent à ce RBAC : identifiants composites,
collections, états readonly, observation d'absence, contrôle optimiste, gardes
coopératives, relecture SQL et flush transactionnel commun avec l'audit.
Le transaction manager DBAL a été extrait dans `UnitOfWork`, avec isolation
configurable et restauration de l'isolation précédente. `observeExists()` a été
ajouté à StateStore pour exprimer une dépendance à l'existence sans observer tout
l'état.

Les query guards déclaratifs couvrent désormais les propriétés et collections
avec une interface exprimée dans le modèle objet. L'exemple utilise cette
[API](../../docs/stored-state/query-guards.md) pour déduire la participation des
writers, sans verrou manuel sur `TenantState`.

Le DDL reste écrit à la main dans `Infrastructure/schema.sql`. Il manque un lien
StateStore → Schema pour dériver tables, identifiants et collections, puis
déclarer les contraintes et index métier. Cette amélioration est consignée dans
[la TODO](../../TODO.md).

La suppression explicite de StateStore figure déjà dans la TODO. Elle serait
utile pour une purge de données, mais ne bloque pas les révocations métier de
cet exemple. Le catalogue, les groupes, l'audit et la politique RBAC appartiennent
au contexte IAM ; ils n'exigent pas un package IAM générique dans Ultra.

## Exécution

Depuis la racine du monorepo, avec PHP 8.5, Composer et Postgres :

```bash
composer install
docker compose up -d
IAM_DATABASE_DSN='pgsql://ultra:ultra@127.0.0.1:55432/ultra_test' \
  php examples/iam/bin/demo.php
ULTRA_TEST_POSTGRES_DSN='pgsql://ultra:ultra@127.0.0.1:55432/ultra_test' \
  bin/phpunit -c examples/iam/phpunit.xml.dist
```

La démonstration installe les tables si elles sont absentes, crée un tenant neuf
et conserve ses données. Elle affiche `ALLOW` pour l'édition via un groupe,
`DENY` pour une autre ressource et pour l'administration, puis `DENY` après
révocation. Elle n'expose pas de serveur HTTP ni de mécanisme d'authentification.
Le SQL fourni installe un schéma neuf ; il ne constitue pas un moteur de migration.

L'exemple peut aussi être installé indépendamment avec `composer install` puis
`ULTRA_TEST_POSTGRES_DSN=... composer test` depuis `examples/iam`. Les tests sont
inclus dans `composer test` à la racine. Ils utilisent des schémas Postgres isolés
et une deuxième connexion pour simuler les décisions concurrentes. Sans DSN,
les tests d'intégration sont ignorés. Après modification des classes de State,
supprimer `examples/iam/var/normalizers` pour régénérer les normalizers ; cette
composition explicite les génère une fois puis les réutilise.

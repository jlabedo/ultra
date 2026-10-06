# TODO

- [ ] Corriger le parcours Mapper pour accepter un même objet normalisé par runtime
  dans plusieurs propriétés sans le confondre avec un cycle. Distinguer les objets
  encore dans le parcours actif de ceux déjà visités ; voir [Mapper](docs/mapping/README.md).

- [ ] Permettre de charger des classes compilées identiques depuis plusieurs
  répertoires dans un même processus. Deux applications avec un bus d'événements
  vide produisent aujourd'hui le même nom de classe et son second chargement
  échoue par redéclaration ; voir les [artefacts de routage](docs/message-handling/README.md#compiled-artifacts).

- [ ] Paginer `EventSourcedStateStore::load()` pour reconstruire les states au-delà
  des 1 000 premiers événements. Protéger la reconstruction sur plusieurs pages ;
  voir la [limite actuelle](docs/event-sourcing/states.md).

- [ ] Rendre atomiques la première acquisition de lease et la première réservation
  de wake dans Coordination Postgres. Sur une clé absente, deux appels concurrents
  peuvent actuellement retourner tous deux `true`, contrairement à l'exclusivité
  et au debounce annoncés. Couvrir ces courses avec deux connexions synchronisées ;
  voir [Coordination](docs/coordination/README.md).

- [ ] Clarifier puis aligner le nombre de clés touchées retourné par `drainSeen()`
  entre mémoire et Postgres. Répéter une observation après avoir vidé son compteur
  retourne actuellement `0` en mémoire et `1` en Postgres, sans travail restant ;
  voir [Coordination](docs/coordination/README.md).

- [ ] Créer les index déclarés lors de la création d'une table dans Schema.
  `CreateTable` conserve les définitions d'index, mais son SQL les omet ; couvrir
  l'effet réel sur Postgres, voir [Schema](docs/schema/README.md).

- [ ] Ordonner les suppressions de colonnes et de leurs index dépendants dans
  Schema. Postgres supprime l'index avec la colonne ; le `DropIndex` émis ensuite
  échoue. Couvrir l'exécution complète de cette migration, voir [Schema](docs/schema/README.md).

- [ ] Ajouter un module de retry au framework. La politique de retry reste
  actuellement à la charge de l'appelant ; définir le périmètre du module,
  notamment les conflits optimistes et son éventuelle articulation avec la
  livraison asynchrone.
  Étudier séparément les nouvelles livraisons de messages : backoff, plafond
  de tentatives et renouvellement des leases pendant les traitements longs.

- [ ] Reprendre l'expérimentation de mise à jour du cache de l'exemple compliance,
  commencée sur une autre branche avec Coordination. Évaluer les primitives
  manquantes à partir de ce cas d'usage et revoir le nom du module si nécessaire.

- [ ] Ajouter la suppression explicite dans StateStore, afin que l'appelant puisse
  supprimer un State sans accéder au MappedRowSet sous-jacent. Préserver les
  attentes optimistes et la participation aux query guards ; préciser le devenir
  des collections du State supprimé. Couvrir la suppression et les conflits avec
  des tests en mémoire et Postgres.

- [ ] Relier les métadonnées StateStore à Schema pour dériver le schéma de base
  (identifiants composites, colonnes et tables des collections), avec déclaration
  des index, contraintes uniques et références. Les exemples coworking et IAM
  maintiennent actuellement le DDL à la main, en parallèle des attributs PHP.
  Ne pas déduire automatiquement les index métier depuis les requêtes SQL.

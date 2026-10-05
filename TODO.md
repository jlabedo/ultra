# TODO

- [ ] Ajouter la suppression explicite dans StateStore, afin que l'appelant puisse
  supprimer un State sans accéder au MappedRowSet sous-jacent. Préserver les
  attentes optimistes et la participation aux query guards ; préciser le devenir
  des collections du State supprimé. Couvrir la suppression et les conflits avec
  des tests en mémoire et Postgres.

- [ ] Déplacer le mécanisme des query guards dans MappedTable et fournir un
  binding StateStore. MappedTable doit porter les définitions physiques
  (tables, colonnes, scopes), la déduction des gardes d'écriture depuis les
  plans, y compris les réconciliations, les observations, la relecture des
  requêtes et l'acquisition des gardes au flush. Conserver un coordinateur
  commun à plusieurs row sets : acquérir toutes les gardes et relire toutes
  les requêtes avant la première écriture, dans une seule transaction.
  Le binding StateStore doit traduire une API exprimée dans le modèle objet,
  par exemple `watch(RoleState::class, scopeBy: 'id.tenantId')`,
  `whenPropertiesChange('active')` et `whenCollectionChanges('permissions')`,
  en dépendances physiques, en tenant compte des identifiants composites et
  des renommages `ownerColumns`. Les dépendances de propriétés et de
  collections se combinent comme un OU. Le scope identifie une garde, pas
  la clé du State. Couvrir ajouts, suppressions, modifications et collections
  vidées, sans lecture supplémentaire pour déduire les scopes. Valider les
  usages directs avec MappedTable, le binding StateStore et la concurrence
  Postgres, notamment une révocation validée pendant l'attente de la garde.
  L'exemple IAM conserve pour l'instant `lock(TenantState)` et
  `observeQuery()` ; sa granularité par tenant est suffisante.

- [ ] Relier les métadonnées StateStore à Schema pour dériver le schéma de base
  (identifiants composites, colonnes et tables des collections), avec déclaration
  des index, contraintes uniques et références. Les exemples coworking et IAM
  maintiennent actuellement le DDL à la main, en parallèle des attributs PHP.
  Ne pas déduire automatiquement les index métier depuis les requêtes SQL.

# Marten et l’event store d’Ultra

Étude au 12 octobre 2026. Ultra : commit `2705be3`, code et suites de garanties lus. Marten : documentation publique `latest (v9.x)` et sources au commit [`b2844a1`](https://github.com/JasperFx/marten/tree/b2844a105f9ae58b15e6ff99215883618b9d1789). La documentation courante comprend des ajouts numérotés jusqu’à 9.47 ; ce rapport ne certifie pas leur disponibilité dans une version NuGet particulière. Aucun benchmark comparatif ni exécution de Marten n’a été réalisé.

Ce document est une étude et une proposition de travail, pas un nouveau contrat ni un engagement de roadmap. Les contrats Ultra restent dans [Event sourcing](../event-sourcing/README.md), [Projection](../projections/README.md) et [MessageHandling](../message-handling/README.md). « Absent » signifie qu’aucun mécanisme dédié n’a été identifié dans les packages concernés ; un développement applicatif reste possible.

## Conclusion

**Le meilleur point de comparaison est le DCB de Marten, pas seulement son modèle historique par aggregate stream.** Les deux systèmes permettent de construire un état à partir d’événements sélectionnés par identifiants et de protéger une décision concurrente. Ultra en fait son modèle central ; Marten conserve également ses streams explicites. [DCB Marten](https://martendb.io/events/dcb), [choix architectural Ultra](../adr/0001-decision-specific-states.md).

Je conserverais le modèle d’Ultra. Les emprunts les plus intéressants concernent :

1. l’optimisation du contrôle de concurrence sans perdre la précision des requêtes ;
2. l’exploitation des projections et leur évolution en production ;
3. l’évolution des événements persistés ;
4. l’accélération du chargement d’état quand les historiques deviennent longs.

Marten n’est pas une preuve qu’il faut reconstruire Ultra autour de racines d’agrégat. Inversement, Ultra ne peut pas considérer le DCB comme une capacité que Marten n’aurait pas.

## 1. Comparatif des capacités

Les références U et M renvoient aux sources regroupées à la fin du document.

| Sujet | Ultra aujourd’hui | Marten examiné | Lecture pour Ultra |
| --- | --- | --- | --- |
| Modèle de stream | Sélection ordonnée par identifiants et types ; pas de propriétaire unique imposé [U1] | Streams identifiés et versionnés, complétés par DCB [M1, M2] | Proximité fonctionnelle, modèles physiques différents |
| Requêtes métier | AND entre identifiants d’une branche, OR entre branches et types [U1] | Conditions OR par tag, avec filtre de type par condition dans l’API documentée [M2] | Vérifier l’équivalence de chaque prédicat ; ne pas supposer des API identiques |
| Concurrence optimiste | Plusieurs `AppendCondition`, chacune sur sa requête exacte [U2] | Version de stream ou assertion de versions de tags [M3] | Comparer précision des conflits et coût sous contention |
| Stockage | JSONB ; identifiants HStore ou table relationnelle commune [U3] | Événements + streams ; DCB TagTables ou HStore [M1, M2] | Les deux familles de stockage existent déjà chez Ultra |
| Transactions | Append atomique ; rejoint la transaction de l’appelant [U3] | Session/unit of work ; persistance des événements à la sauvegarde [M4] | Capacité déjà présente, avec assemblage différent |
| Lecture des écritures en attente | Buffer ajouté une fois à la dernière page ; replay borné exclut le buffer [U4] | `ProjectLatest` inclut les événements en attente [M5] | Comparer les contrats de session plutôt qu’annoncer une absence |
| État calculé à la demande | Loaders compilés ; replay paginé, mémoire bornée par page [U5] | Live aggregation et agrégations DCB [M2, M6] | Ultra dispose déjà de ce socle |
| Snapshots d’état | Pas de cache/snapshot intégré au chargeur examiné [U5] | Snapshots et stratégies de lecture d’agrégats [M6] | Gain potentiel sur historiques longs |
| Projections asynchrones | Partitions, segments, leases, checkpoints et coordination [U6] | Daemon, shards, progression persistée [M7] | Investir dans l’exploitation plutôt que réécrire le moteur |
| Projections inline | Publication synchrone des événements possible ; pas de lifecycle inline équivalent dans Projection [U7, U6] | Lifecycle inline transactionnel [M8] | Option utile si un read model doit être à jour au commit |
| Rebuild | Reset et replay/backfill déjà disponibles [U6] | Rebuild, limitation de concurrence, versions de projections [M9] | Le manque porte sur le rebuild en parallèle et la bascule |
| Métadonnées | JSONB ; contexte, message, corrélation et causalité propagés [U7] | Métadonnées d’événement, headers et contexte [M10] | Déjà présent ; identité persistante/indexation à distinguer |
| Évolution des payloads | Noms d’événements explicites ; pas de chaîne d’upcasting dédiée identifiée [U8] | Mapping des noms et upcasting [M11] | Extension prioritaire |
| Multi-tenancy | Identifiant métier possible, sans isolation systématique imposée par EventStore [U1, U3] | Tenancy intégrée et configurations par base [M12] | Pertinent si produit SaaS ; un tag seul n’est pas une barrière d’isolation |
| Archivage/compaction | Pas d’API dédiée dans EventStore [U1] | Archivage de streams et compaction [M13] | Difficile à transposer quand un événement sert plusieurs états |
| Résilience/diagnostic | Rollback, reprise des claims, commandes de segments ; retry général encore prévu [U6, U9] | Politiques d’erreur du daemon, dead letters, health checks [M7, M14] | Fort rendement opérationnel |

## 2. Concurrence : le compromis le plus instructif

### Ultra : protéger la requête précise

L’append acquiert les verrous, recherche un éventuel nouvel événement après chaque checkpoint, puis insère le lot. Le contrôle utilise la même requête que la lecture. Les tests vérifient notamment qu’un événement d’un autre type, ou d’une autre paire cours/étudiant, n’invalide pas la décision. [Implémentation](../../src/EventSourcing/Postgres/PostgresEventStoreHStore.php), [garanties](../../src/EventSourcing/Tests/OptimisticAppendTest.php).

Le coût est dans `StreamQueryLockKeys` : pour **d identifiants**, chaque événement génère **2 × (2^d − 1)** clés logiques avant déduplication entre événements, pour les variantes typées et wildcard de tous les sous-ensembles non vides. Cela donne 14 clés à 3 dimensions, 62 à 5, 510 à 8 et 2 046 à 10. C’est une déduction directe de l’algorithme, pas une mesure de latence. [Source](../../src/EventSourcing/Postgres/LockStrategy/StreamQueryLockKeys.php).

Les deux stratégies existantes modulent déjà ce coût : `shared-exclusive` et `bucket`. La seconde permet à des écritures sans garde de partager un namespace, tout en sérialisant les conflits lecture/écriture. Elle n’élimine pas l’énumération des sous-ensembles. [Stratégie bucket](../../src/EventSourcing/Postgres/LockStrategy/BucketLockStrategy.php), [concurrence réelle](../../src/EventSourcing/Tests/ConcurrentAppendTest.php).

### Marten : un point de conflit matérialisé

Les sources examinées capturent une version par couple type/valeur de tag et tenant. À la sauvegarde, une assertion conditionnelle incrémente la version ; les producteurs d’événements tagués actualisent eux aussi ces lignes. Une ligne absente est créée par UPSERT. Les assertions sont triées et dédupliquées, en conservant la plus ancienne version observée. [Capture](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/FetchForWritingByTagsHandler.cs), [assertion](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/DcbTagVersionAssertion.cs), [producteur](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/DcbTagVersionBumpOperation.cs).

**Différence importante déduite du code :** la clé de version n’inclut pas le type d’événement sélectionné. Une décision qui lit uniquement `Subscribed` pour un cours peut donc rencontrer un conflit après un `CourseRenamed` tagué avec ce même cours. Ultra teste explicitement l’absence de ce genre de conflit hors requête. Le mécanisme de Marten est conservateur ; ce n’est pas une preuve de résultat métier incorrect.

La capture des versions précède la lecture des événements. Cet ordre évite d’associer un ancien état à une version déjà actualisée ; sous `READ COMMITTED`, il peut toutefois provoquer un conflit conservateur supplémentaire. C’est un détail à conserver dans tout prototype inspiré de cette approche. [Capture](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/FetchForWritingByTagsHandler.cs).

### Recommandation

Ne pas remplacer les verrous d’Ultra par des versions par tag sans décision explicite sur les conflits supplémentaires. Explorer d’abord deux options :

- des verrous plus grossiers par identifiant, suivis de la validation **exacte** actuelle : moins de namespaces, mais davantage d’attente entre écritures indépendantes ;
- des namespaces limités aux formes de requêtes déclarées, avec un traitement explicite des requêtes ad hoc : coût potentiellement réduit, mais nouveau contrat entre compilation et runtime.

Ce sont des hypothèses de conception. Un prototype doit conserver les scénarios de courses simultanées, absence d’historique, plusieurs conditions, types wildcard, alias et commits tardifs. La stratégie de Marten peut servir de troisième variante, avec ses conflits supplémentaires mesurés comme un coût fonctionnel.

## 3. Stockage et chemins SQL

Ultra HStore stocke les identifiants dans la ligne événement et utilise un index GIN composite. QueryStreams conserve une table commune `event_domain_ids` et construit des prédicats `EXISTS` par dimension ; son append insère d’abord les événements avec `RETURNING sequence`, puis les identifiants. Ce n’est pas une table par stream. [Schéma HStore](../../src/EventSourcing/Postgres/PostgresEventStoreHStoreSchema.php), [QueryStreams](../../src/EventSourcing/Postgres/PostgresEventStoreQueryStreams.php).

Marten propose une table par type de tag avec des valeurs typées, ou HStore avec des valeurs textuelles. TagTables peut porter plusieurs valeurs d’un même type ; HStore ne le peut pas. Ultra impose une valeur par nom dans les deux backends et traite certains cas via des alias, par exemple `roomDay` et `previousRoomDay`. [Marten DCB](https://martendb.io/events/dcb), [alias Ultra](../event-sourcing/states.md).

Piste immédiatement applicable : éviter de charger et désérialiser un événement uniquement pour détecter un conflit. L’append Ultra appelle actuellement `load(..., batchSize: 1)`, qui récupère payload et métadonnées et inspecte une ligne supplémentaire. Un chemin interne `EXISTS` pourrait préserver exactement les verrous et prédicats actuels. Marten expose déjà `EventsExistAsync`. [Ultra](../../src/EventSourcing/Postgres/PostgresEventStoreHStore.php), [Marten](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/EventsExistByTagsHandler.cs).

Les appends « Quick » de Marten déplacent du travail dans PostgreSQL ; leur compromis inclut l’indisponibilité de certaines positions/version avant persistance, notamment inline. Ultra fait déjà des INSERT multi-lignes et n’expose pas la séquence sur son `Event`. Il faut mesurer les allers-retours avant de porter une procédure stockée ; aucun gain chiffré Marten n’est transposable directement. [Appending](https://martendb.io/events/appending).

**Limite Ultra repérée :** les requêtes de segments portent un `bucketRange`. QueryStreams le traduit en SQL, tandis que le générateur de prédicats HStore examiné ne le traite pas. Les suites PostgreSQL de Projection utilisent QueryStreams. Ne pas présenter les deux backends comme interchangeables pour les projections segmentées sans vérification dédiée. Observation statique, non reproduite en base dans cette étude. [Branche](../../src/EventSourcing/EventStore/StreamQueryBranch.php), [HStore](../../src/EventSourcing/Postgres/PostgresEventStoreHStore.php), [fixture](../../src/Projection/Tests/Support/ProjectionFixture.php).

## 4. Positions, trous et ordre de projection

Ultra utilise `xid`, `pg_current_snapshot()` et un checkpoint constitué d’un plancher et de séquences **déjà observées au-dessus du plancher**. `gapSeqNos` n’est donc pas la liste des événements manquants. Cela permet de récupérer ensuite un commit tardif de séquence inférieure, sans répéter les événements déjà vus, et de rejouer l’ensemble capturé avec `to`. [Checkpoint](../../src/EventSourcing/EventStore/Checkpoint.php), [tests](../../src/EventSourcing/Tests/CheckpointReplayTest.php).

Le daemon Marten avance jusqu’à un high-water mark sûr. La documentation actuelle décrit une attente tant qu’une transaction peut encore remplir un trou, puis un saut du trou prouvé mort. Un plafond d’attente optionnel réintroduit un risque de manquer un commit tardif. Ce n’est donc pas simplement un timeout fixe. [Daemon](https://martendb.io/events/projections/async-daemon).

Les deux mécanismes répondent à des besoins différents. Le checkpoint Ultra mémorise ce qui a été vu ; il ne remet pas rétroactivement un événement tardif avant ceux déjà appliqués. Exemple : appliquer 12 puis découvrir 11 peut produire un résultat différent de 11 puis 12 pour un fold non commutatif.

Le contrat Projection promet l’ordre par dimension de partition. Il faut donc vérifier ce contrat avec les différentes stratégies d’append, particulièrement les écritures concurrentes permises par `bucket`, dans un scénario de commits inversés sur **la même partition**. La présence de tests de catch-up sans perte ne suffit pas à démontrer l’ordre d’application entre plusieurs pages. C’est un audit recommandé, pas un défaut de projection reproduit ici. [Contrat](../projections/README.md), [concurrence](../../src/EventSourcing/Tests/ConcurrentAppendTest.php).

Autre point à mesurer : une transaction longue peut maintenir le plancher bas. `Checkpoint::record()` recrée et normalise la liste des séquences observées ; sa taille et le coût des lectures méritent une mesure sous transaction longue, puis après sa clôture. Une stratégie de watermark peut inspirer une option de replay stable, sans supprimer le checkpoint DCB existant.

## 5. Fonctionnalités pertinentes à ajouter

| Priorité proposée | Extension | Pourquoi et périmètre minimal | Garantie à tester |
| --- | --- | --- | --- |
| P1 | Upcasters | Transformer les anciens payloads avant hydratation, sans réécrire l’historique. Noms persistés stables et fixtures historiques ; commencer par du un-vers-un [M11] | Même historique ancien accepté par états compilés et projections ; erreur explicite si transformation impossible |
| P1 | Diagnostic et erreurs durables des projections | État bloqué, dernière erreur, âge du backlog, tentatives, pause/reprise ; retry/backoff dans les contextes responsables [M7, M14] | Un échec ne valide pas le checkpoint ; reprise contrôlée ; aucun événement ignoré silencieusement |
| P1/P2 | Projections versionnées et rebuild parallèle | Écrire une version suivante dans d’autres tables/checkpoints, la rattraper, puis basculer la lecture ; limiter les workers et connexions [M9] | Écritures concurrentes pendant rebuild prises en compte ; ancienne version disponible jusqu’à la bascule |
| P2 | Snapshots/cache d’état | Baseline + checkpoint + version du loader + identité de requête ; replay du delta [M6] | Ne jamais réutiliser un snapshot incompatible ; gérer événements tardifs et buffer pending sans double application |
| P2 | Identité persistante et append idempotent | Clé stable d’opération/événement et contrainte d’unicité ; distinguer retry technique et nouvelle décision | Après commit à résultat réseau ambigu, retenter ne duplique pas le lot ; définir le comportement si même clé et contenu différent |
| P2 selon produit | Tenancy imposée | Tenant injecté dans toutes les lectures, écritures, gardes, checkpoints de projection et tâches de fond [M12] | Deux tenants avec les mêmes identifiants métier restent isolés, y compris au rebuild |
| P3 | Lifecycle inline explicite | Read models simples actualisés dans la transaction d’append, sans imposer ce coût aux projections lourdes [M8] | Échec du read model annule append et progression ; pas d’effet externe non transactionnel |
| P3 | Identifiants multivalués | Représenter plusieurs participants sous une même dimension, si les alias deviennent artificiels [M2] | Sémantique AND/OR, déduplication, partitionnement et conflits définis avant changement |
| P3 selon volume | Archivage/compaction | Définir la rétention par usage et les historiques encore nécessaires [M13] | Aucun état décisionnel ni rebuild dépendant n’est privé d’un événement partagé |

Ces priorités expriment le rendement attendu pour un framework, pas des besoins métier confirmés. P1/P2 signifie : P1 si les read models doivent rester disponibles pendant leur évolution, sinon après les premiers besoins de performance.

Deux précisions évitent de surévaluer les manques :

- Ultra possède déjà des métadonnées de message et une outbox. L’idempotence proposée vise une contrainte durable d’append, pas l’ajout d’un simple champ `message_id`. Marten fournit une identité événement dans son stockage, avec unicité SQL configurable ; cela ne suffit pas à promettre un protocole complet de retry idempotent. [U7, U9, M1]
- Reset, backfill, leases et rollback existent déjà. Les dead letters ou le skip d’événement nécessitent une politique explicite : préserver la disponibilité en sautant un événement peut rendre un read model incomplet. Il ne faut pas importer une politique de tolérance sans son impact métier.

Une inspiration concrète pour les snapshots est `FetchLatest` : Marten peut compléter en mémoire un snapshot asynchrone avec les événements encore non projetés. Pour Ultra, cette idée doit être adaptée aux états multi-identifiants et à leurs checkpoints avec trous, plutôt que copiée depuis la version d’un stream. [Lecture d’agrégats](https://martendb.io/events/projections/read-aggregates).

Pour la santé des projections, mesurer uniquement le retard par rapport à une borne peut masquer une borne elle-même bloquée. Marten distingue ces contrôles. Dans Ultra, suivre séparément activité en attente, progrès des checkpoints, ancienneté des claims et dernière erreur serait plus utile qu’un unique compteur de retard. [Health checks](https://martendb.io/events/projections/healthchecks).

## 6. Ordre de travail recommandé

1. **Établir la garantie avant d’optimiser** : valider le filtrage des segments HStore et l’ordre sous commits inversés sur une même partition.
2. **Mesurer le chemin actuel** : compléter le benchmark existant avec 1/3/5/8/10 dimensions, tags chauds/froids, producteurs réellement concurrents, transactions longues, requêtes typées/non typées et paires d’identifiants.
3. **Essayer le contrôle d’existence interne**, puis comparer une stratégie de verrouillage moins combinatoire. Mesurer débit, p95/p99, temps d’attente, conflits, taille des checkpoints, volume WAL et nombre de requêtes ; conserver les tests de précision de la garde.
4. **Faire les upcasters et le diagnostic des projections**, puis la reconstruction versionnée si le déploiement sans indisponibilité est requis.
5. **Introduire les snapshots sur un cas lent mesuré**, en traitant d’abord les commits tardifs : un checkpoint seul ne rend pas un fold incrémental correct si un événement ancien doit s’insérer avant le snapshot.

Le benchmark Ultra existant fournit les variantes HStore/QueryStreams et shared-exclusive/bucket, des charges déterministes et des vérifications de résultat. Ses mesures séquentielles ne mesurent pas la contention. C’est le bon point de départ plutôt qu’un classement PHP contre .NET sur des charges différentes. [Benchmarks](../event-sourcing/postgres.md#benchmarks).

## Sources et vérification

### Ultra

- **U1** : [contrat EventStore](../event-sourcing/event-store.md), [interface](../../src/EventSourcing/EventStore/EventStore.php).
- **U2** : [OptimisticAppendTest](../../src/EventSourcing/Tests/OptimisticAppendTest.php), [ConcurrentAppendTest](../../src/EventSourcing/Tests/ConcurrentAppendTest.php).
- **U3** : [PostgreSQL](../event-sourcing/postgres.md), [HStore](../../src/EventSourcing/Postgres/PostgresEventStoreHStore.php), [QueryStreams](../../src/EventSourcing/Postgres/PostgresEventStoreQueryStreams.php), [AppendTransactionTest](../../src/EventSourcing/Tests/AppendTransactionTest.php).
- **U4** : [EventStoreUnitOfWork](../../src/EventSourcing/EventStore/EventStoreUnitOfWork.php), [BufferedEventsTest](../../src/EventSourcing/Tests/BufferedEventsTest.php).
- **U5** : [états](../event-sourcing/states.md), [EventSourcedStateStore](../../src/EventSourcing/State/EventSourcedStateStore.php), [StateReconstructionTest](../../src/EventSourcing/Tests/StateReconstructionTest.php).
- **U6** : [contrat Projection](../projections/README.md), [ProjectionRunner](../../src/Projection/Runtime/ProjectionRunner.php), [BatchAtomicityTest](../../src/Projection/Tests/BatchAtomicityTest.php), [OperatorCommandsTest](../../src/Projection/Tests/OperatorCommandsTest.php).
- **U7** : [Event](../../src/EventSourcing/EventStore/Event.php), [EventPublicationTest](../../src/MessageHandling/Tests/Stateful/EventPublicationTest.php), [EventSourcedDecisionAspect](../../src/MessageHandling/Stateful/EventSourcedDecisionAspect.php).
- **U8** : [identité événement](../../src/EventSourcing/Tests/EventIdentityTest.php), [runtime de projection](../../src/Projection/Runtime/ProjectionRuntime.php).
- **U9** : [livraison async](../message-handling/async.md), [TODO](../../TODO.md).

### Marten

- **M1** : [stockage](https://martendb.io/events/storage).
- **M2** : [DCB](https://martendb.io/events/dcb).
- **M3** : [assertion de versions de tags, code figé](https://github.com/JasperFx/marten/blob/b2844a105f9ae58b15e6ff99215883618b9d1789/src/Marten/Events/Dcb/DcbTagVersionAssertion.cs).
- **M4** : [append et modes Rich/Quick](https://martendb.io/events/appending).
- **M5** : [ProjectLatest](https://martendb.io/events/projections/project-latest).
- **M6** : [snapshots](https://martendb.io/events/projections/single-stream-projections), [lecture d’agrégats](https://martendb.io/events/projections/read-aggregates), [optimisation](https://martendb.io/events/optimizing).
- **M7** : [daemon async](https://martendb.io/events/projections/async-daemon).
- **M8** : [projections inline](https://martendb.io/events/projections/inline).
- **M9** : [rebuild](https://martendb.io/events/projections/rebuilding).
- **M10** : [métadonnées](https://martendb.io/events/metadata).
- **M11** : [versioning et upcasting](https://martendb.io/events/versioning).
- **M12** : [multi-tenancy](https://martendb.io/events/multitenancy).
- **M13** : [archivage](https://martendb.io/events/archiving), [compaction](https://martendb.io/events/compacting).
- **M14** : [health checks](https://martendb.io/events/projections/healthchecks).

Méthode : lecture des contrats, des implémentations et des tests Ultra ; consultation des sources primaires Marten et de son code figé pour le contrôle DCB. Les tests cités sont des preuves documentaires du périmètre couvert, pas des tests exécutés pendant cette étude. Les propositions de performance restent à mesurer. Aucun code de production ni contrat courant n’a été modifié.

# Mes opportunités — Pack Classic

Fonctionnalité permettant aux clients du Pack Classic de déclarer un profil
de recherche (poste, secteur, compétences, mobilité...), de recevoir des
offres internes NexRaiz notées par un score de correspondance 0-100, une
analyse IA de leur profil/CV/lettre de motivation, une lettre de motivation
rédigée par l'IA si besoin, des liens de recherche pré-remplis vers des
sites d'emploi externes, et — pour les offres avec une adresse e-mail de
candidature — l'envoi direct de la candidature depuis NexRaiz après
validation du client.

**Sur le sourcing des offres réelles (Educarrière, LinkedIn, etc.)** :
**NexRaiz n'extrait (scrape) aucun site externe**, ce qui est interdit par
leurs CGU — en particulier LinkedIn, qui poursuit activement les scrapers.
Recherche effectuée lors du développement de cette note (novembre 2026) :
ni Educarrière (aucune API/flux RSS publique trouvée), ni les deux
agrégateurs d'offres gratuits les plus connus (Jooble, Adzuna) ne couvrent
la Côte d'Ivoire. La seule source 100 % réelle et légale actuellement
disponible est donc la **saisie manuelle par les consultants**
(`opportunites-admin.php`), qui consultent eux-mêmes Educarrière/LinkedIn/
emploi.ci et saisissent l'offre réelle (avec sa vraie adresse de
candidature). Le connecteur `includes/opportunites-connecteurs.php` reste
prêt à être branché sur un futur partenariat officiel (ex. un accès API
négocié directement avec Educarrière) sans qu'aucune UI ne change.

## Accès réservé aux profils réellement analysés par l'IA (correctif du 23/11/2026)

**Le consentement seul ne suffit jamais** à afficher des recommandations :
`opportunites_profil_actif(?array $profil): bool` (dans `includes/opportunites.php`)
exige `consent_matching = 1` **ET** `cv_document_id` renseigné (le document CV
réellement utilisé par `opportunites_extraire_profil_cv()`). Toute fonction
qui décide si un dossier voit des offres — `opportunites_recommandees_pour_dossier()`,
`classic_opportunites_apercu()` (vue coach), les deux requêtes de notification
du cron — passe par cette règle.

**Bug corrigé** : `opportunites_enregistrer_profil()` n'écrivait jamais
`cv_document_id` en base (colonne prévue dans le schéma dès l'origine, mais
oubliée de l'`INSERT`/`UPDATE`), donc **aucun** profil n'avait ce champ
renseigné — un dossier avec le consentement coché voyait des offres même si
son profil n'avait en réalité jamais été construit à partir d'un CV analysé
(cas constaté en production sur un dossier réel). Corrigé : `cv_document_id`
est maintenant persisté par `opportunites_enregistrer_profil()`, fourni par
`classic-mon-profil-recherche.php` depuis le document CV réellement déposé.
Les profils existants ont été audités manuellement un par un (pas de script
de correction en masse, pour ne jamais fabriquer un `cv_document_id` qui ne
correspondrait pas à un vrai CV analysé) : un dossier a été corrigé parce que
son CV réel était vérifiable, un autre a été laissé inactif faute de CV
actuellement déposé — il devra redéposer un CV et relancer l'analyse.

## Variables d'environnement (`includes/env.local.php`)

Mêmes conventions que `includes/ai-config.php`/`includes/payment-config.php` :
pas de fichier `.env`, tout passe par `putenv()` dans `includes/env.local.php`
(gitignored). Voir « Écart assumé » plus bas.

| Variable | Rôle | Défaut |
|---|---|---|
| `AI_API_KEY` | Clé API Anthropic (Claude), pour l'analyse de profil/CV. **Jamais** la même clé que `GROQ_API_KEY` (chatbot/correction de documents) : fonctionnalité distincte, demandée explicitement sur Anthropic. | `REPLACE_ME` (désactive l'IA, repli automatique) |
| `AI_MODEL` | Modèle Anthropic utilisé | `claude-3-5-haiku-latest` |
| `JOB_API_ENABLED` | Active l'import automatique d'offres externes via un connecteur (`1` ou absent/`0`) | `0` (désactivé) |
| `JOB_API_KEY` | Clé du connecteur (ex. Jooble) | `REPLACE_ME` |
| `JOB_API_PROVIDER` | Nom du connecteur à utiliser (voir `includes/opportunites-connecteurs.php`) | `jooble` |
| `OPPORTUNITES_SEUIL_SCORE` | Score minimum (0-100) pour qu'une offre soit affichée au client | `40` |
| `OPPORTUNITES_SEUIL_NOTIF` | Score minimum pour déclencher une notification « nouvelle offre » | `70` |

Si `AI_API_KEY` n'est pas configurée (ou que l'appel Anthropic échoue), le
client voit quand même une analyse — étiquetée honnêtement « Analyse
simplifiée (sans IA) » — basée sur une extraction de mots-clés simple
(`opportunites_analyse_secours()`). Le code ne plante jamais faute d'IA.

## Utilisation côté consultant/admin (`opportunites-admin.php`)

Accessible aux rôles `consultant` et `admin` (volontairement plus large que
`ressources.php`, qui est admin-only : ce sont les consultants qui
connaissent le mieux les besoins de leurs clients Pack Classic — voir
« Décisions ouvertes » ci-dessous).

- **Créer une offre** : formulaire en haut de page (titre, type, entreprise,
  secteur, ville, télétravail, niveau d'études min., expérience min.,
  compétences requises séparées par des virgules, description, lien ou
  e-mail de candidature, salaire indicatif, date limite). Créée en
  **brouillon**, invisible des clients jusqu'à publication.
- **Modifier** : lien « Modifier » sur chaque offre, rouvre le même
  formulaire pré-rempli.
- **Publier / Dépublier** : bascule immédiate de visibilité côté client.
- **Dupliquer** : copie une offre existante en nouveau brouillon (utile
  pour republier une offre similaire).
- **Expirer les offres échues** (bouton en haut) : déclenche manuellement
  ce que le cron fait automatiquement toutes les heures — utile pour
  tester sans attendre le cron.
- Filtres par statut/type/ville en haut de la liste.

## Vue coach (`consultant-opportunites-classic.php`)

Liste des clients Pack Classic ayant consenti au matching, avec 3
compteurs (recommandées / enregistrées / candidatures). Cliquer sur un
client ouvre une vue **lecture seule** : son profil de recherche, ses
offres recommandées avec score, et son suivi de candidature. Aucune action
de mutation ici — la vue sert à suivre l'accompagnement, pas à modifier les
offres (ça, c'est `opportunites-admin.php`).

## Activer le connecteur d'import d'offres externes

Désactivé par défaut (`JOB_API_ENABLED=0`). Pour l'activer :

1. Obtenir une vraie clé API chez le fournisseur (ex. Jooble).
2. Définir `JOB_API_ENABLED=1`, `JOB_API_KEY=...`, `JOB_API_PROVIDER=jooble`
   dans `includes/env.local.php`.
3. Le cron (`cron/opportunites-cron.php`) importera alors les offres en
   **brouillon** (jamais publiées automatiquement — un consultant doit les
   relire et les publier manuellement) via
   `includes/opportunites-connecteurs.php::jooble_importer_offres()`.
4. Pour ajouter un autre fournisseur : écrire une fonction
   `xxx_importer_offres(array $config): array` retournant un tableau
   d'offres (`titre`, `type`, `entreprise`, `ville`, `description`,
   `lien_candidature`, `date_limite`, `source_reference`), puis l'ajouter
   au registre `opportunites_connecteurs_disponibles()` dans
   `includes/opportunites-connecteurs.php`. Le dédoublonnage (`UNIQUE(source,
   source_reference)`) est automatique.

**Le scraping de sites tiers (LinkedIn, Educarrière, Emploi.ci, Agence
Emploi Jeunes, GoAfricaOnline) n'est ni implémenté ni prévu** : c'est
interdit par leurs conditions d'utilisation. Les 5 liens affichés au client
sont des recherches pré-remplies qu'il ouvre lui-même.

## Offres fictives retirées — remplacées par de vraies offres (22/11/2026)

Les 5 offres `[DÉMO]` qui peuplaient la base au premier build de cette
fonctionnalité ont été **retirées** : un client les a prises pour de vraies
opportunités et a tenté de postuler, ce qui a révélé le problème (lien vers
un domaine inventé → erreur DNS dans le navigateur). `database/schema.sql`
ne seede plus aucune offre, fictive ou réelle — voir « Écart assumé » plus
bas sur pourquoi une offre réelle ne doit jamais être codée en dur dans un
script rejouable.

À la place, **2 vraies offres ont été saisies manuellement** (comme le
prescrit ce document) en lisant directement des annonces publiques sur
GoAfricaOnline, de la même façon qu'un consultant le ferait via
`opportunites-admin.php` :

- *Assistante stagiaire* — Dav'Holding Group, Abidjan (stage).
- *Commercial(e) — menuiserie aluminium* — LUX'ALU SOLUTIONS, Abidjan (emploi).

**Ce qui a été appris en le faisant, utile pour la suite de la saisie manuelle :**

- **La plupart des annonces indexées par les moteurs de recherche sont déjà
  expirées.** Sur un échantillon de 8 annonces GoAfricaOnline/Simoon-CV
  vérifiées le 22/11/2026, une seule avait une candidature par e-mail
  directement exploitable, et la majorité affichait une date limite déjà
  dépassée (souvent de plusieurs mois). Un consultant doit privilégier les
  annonces les **plus récemment publiées** (quelques jours), pas les
  premiers résultats d'une recherche.
- **Ne jamais deviner une adresse e-mail — mais la déchiffrer si elle est
  honnêtement récupérable.** Plusieurs annonces (dont LUX'ALU SOLUTIONS
  ci-dessus) protègent l'e-mail contre les robots simples via
  l'obfuscation « Cloudflare Email Protection » : la page source contient
  un attribut `data-cfemail="<hex>"` à la place de l'adresse, que
  n'importe quel navigateur réel déchiffre à l'affichage. Ce n'est **pas**
  un secret — c'est un simple XOR réversible (le premier octet hex est la
  clé, chaque octet suivant est XORé avec elle pour obtenir le caractère
  ASCII réel), documenté publiquement par Cloudflare. Le récupérer ainsi
  n'est donc pas « deviner » : c'est lire exactement ce qu'un visiteur
  humain verrait. `WebFetch`/les agents de ce projet convertissent la page
  en markdown et perdent cet attribut — il faut récupérer le HTML brut
  (ex. `Invoke-WebRequest` avec un User-Agent standard) et y chercher
  `data-cfemail="..."` pour l'obtenir. **Seulement si cet attribut est
  introuvable** (adresse vraiment absente de la page), laisser
  `email_candidature` vide et renseigner uniquement `lien_candidature`.
- **Se méfier des dates limites affichées** : au moins une annonce montrait
  une date limite manifestement aberrante (année 2030 sur une offre publiée
  en novembre 2026). Dans le doute, laisser `date_limite` vide (l'offre
  n'expire alors jamais automatiquement — il faudra la dépublier
  manuellement une fois qu'elle n'est plus d'actualité) plutôt que de
  stocker une date visiblement fausse.
- Cette saisie reste **ponctuelle** : comme toute offre réelle, ces deux
  entrées deviendront obsolètes (quelques semaines) et devront être
  dépubliées/remplacées par les consultants via `opportunites-admin.php` —
  ce n'est pas un mécanisme qui s'auto-entretient.

## Réglage des poids du score de correspondance

Dans `includes/opportunites-config.php`, clé `poids_score` (doit sommer à
100) : `type`, `ville`, `niveau_etudes`, `experience`, `competences`,
`intitule`. Le calcul lui-même est dans
`includes/opportunites.php::calculer_score_match()` — fonction pure, jamais
mise en cache, recalculée à chaque lecture. Voir `tests/test_score_calcul.php`
pour les cas limites couverts (profil sans préférence, télétravail
compensant une ville différente, demi-crédit à un an de l'expérience
demandée, etc.).

## Analyse IA basée sur CV + lettre de motivation, et génération de lettre

L'analyse IA (`includes/opportunites-ia.php::opportunites_analyser_profil()`)
lit désormais **le CV et la lettre de motivation réels du client** (slots
`cv` et `lettre` de `classic-depot-documents.php`), jamais seulement le CV.
Le cache (`classic_analyses_ia_opportunites`) se recalcule dès que l'un des
deux change (`cv_hash`/`lettre_hash`).

Si le client n'a pas de lettre de motivation, il peut en demander une pour
une offre précise (bouton « Générer avec l'IA » sur
`classic-mes-opportunites.php`) : `opportunites_generer_lettre_ia()` rédige
un texte basé **uniquement** sur le profil/CV/offre réels (jamais
d'expérience ou de diplôme inventé), que le client peut relire et modifier
avant tout envoi. Sans IA configurée, un modèle simplifié avec des
placeholders entre crochets est utilisé à la place
(`opportunites_lettre_secours()`), jamais un texte halluciné.

## Postuler directement via NexRaiz

Pour une offre publiée avec une adresse e-mail de candidature
(`email_candidature`), le client peut cliquer « Postuler via NexRaiz » :
un panneau s'ouvre avec sa lettre de motivation (pré-remplie si déjà
déposée, ou générée par l'IA sur demande), qu'il relit et modifie avant de
cliquer « Valider et envoyer ma candidature ».

`opportunites_candidater_via_nexraiz()` (`includes/opportunites.php`)
envoie alors réellement l'e-mail — CV joint automatiquement, lettre en
corps de message — via `nx_envoyer_candidature()`
(`includes/mailer.php`, PHPMailer, ajouté comme dépendance Composer pour
fiabiliser l'envoi de pièce jointe plutôt qu'un MIME multipart construit à
la main). **Expéditeur technique = NexRaiz, mais `Reply-To` = l'adresse
réelle du client** : usurper son adresse en "From" ferait échouer les
vérifications anti-spoofing (SPF/DKIM) du serveur du recruteur et risquerait
de faire atterrir le message en spam. N'envoie jamais si la lettre est vide
ou si le client n'a pas encore déposé son CV ; marque la candidature comme
« postulée » uniquement en cas de succès réel de l'envoi.

## Préparation du futur espace recruteur

La structure de données anticipe déjà un espace recruteur (hors périmètre
de cette livraison) :
- `opportunites.source` accepte déjà `'recruteur'` (en plus de `'admin'` et
  `'api_partner'`), et `opportunites.recruiter_id` (FK nullable → `users`)
  est prête à recevoir l'identité du recruteur une fois ce portail construit.
- `classic_profils_recherche.consent_recruteurs` (+ sa date) est un
  consentement **distinct** du consentement matching, révocable
  séparément — c'est lui qui conditionnera la visibilité du profil par un
  recruteur externe. Pour l'instant, aucune UI ne l'exploite : seul le
  consentement est collecté et stocké, prêt à l'emploi.

## Tâche planifiée (cron)

```
php /chemin/vers/nexraiz_phase/cron/opportunites-cron.php
```

À exécuter toutes les heures (crontab cPanel). Ordre des opérations :
expiration des offres échues → import connecteur (si activé) →
notification des nouvelles offres ≥ seuil → rappel d'échéance à J-3.
Chaque exécution journalise un résumé via `error_log()`. Chaque notification
est dédupliquée par `UNIQUE(opportunite_id, dossier_id, type)` : un second
passage ne renvoie jamais la même notification deux fois.

## Tests

```
php tests/run.php
```

Transaction PDO ouverte en début de script et **jamais commitée** : toutes
les données créées par les tests disparaissent au rollback de fin de
script, sans toucher aux données réelles. Couvre : le calcul du score (cas
limites), le contrôle d'accès (pas de consentement → zéro recommandation,
même avec une offre parfaitement compatible), l'expiration automatique, et
le repli sans IA quand la clé Anthropic n'est pas configurée.

## Écart assumé vis-à-vis du cahier des charges d'origine

Le cahier des charges demandait un fichier `.env` et des migrations de base
réversibles. **Décision confirmée avec l'équipe projet** : ces nouvelles
tables réutilisent les conventions déjà en place dans tout NexRaiz —
`includes/env.local.php` (gitignored, `putenv()`) pour les secrets, et des
blocs `CREATE TABLE IF NOT EXISTS` ajoutés à `database/schema.sql` (rejoués
à la main sur la base de production), sans outil de migration. Introduire
`.env`/dotenv ou un outil de migration pour cette seule fonctionnalité
aurait créé deux façons différentes de gérer les secrets et le schéma dans
la même base de code. À reconsidérer si NexRaiz adopte ces outils
globalement un jour.

De la même façon, les tests sont des scripts PHP autonomes
(`tests/run.php`), pas une suite PHPUnit : aucune dépendance de dev
n'existe aujourd'hui dans ce projet.

## Points à décider par l'équipe NexRaiz

1. **Seuils de score** (40 affichage / 70 notification) : à confirmer, ou à
   rendre réglables depuis l'admin plutôt que figés dans la config.
2. **Gabarits d'URL des 5 sites de recherche** (`includes/opportunites-config.php`,
   clé `liens_recherche`) : écrits par déduction, **pas vérifiés un par un**
   contre le comportement réel de LinkedIn/Educarrière/Emploi.ci/Agence
   Emploi Jeunes/GoAfricaOnline. À tester manuellement avant mise en ligne.
3. **Texte juridique exact** des deux cases de consentement
   (`classic-mon-profil-recherche.php`) : à valider par l'équipe/un juriste
   avant mise en production, notamment au regard de la loi ivoirienne sur
   la protection des données (ARTCI).
4. **Pondération du score** (type/ville/niveau/expérience/compétences/
   intitulé, actuellement 15/15/15/15/30/10) : à confirmer, ou à rendre
   éditable sans déploiement (ex. depuis l'admin).
5. **Mutation des offres ouverte aux consultants et aux admins** (pas
   admin-only comme `ressources.php`) : à confirmer que c'est le
   comportement souhaité.
6. **Modèle Anthropic** (`claude-3-5-haiku-latest` par défaut, rapide/
   économique) : à confirmer face à un modèle plus capable si la qualité
   de l'analyse déçoit en usage réel.
7. **Source des offres réelles** : aucune API/flux gratuit ne couvre la
   Côte d'Ivoire à ce jour (Educarrière, Jooble, Adzuna vérifiés en
   novembre 2026 — voir l'introduction de ce document). Un premier passage
   de saisie manuelle réelle a été fait le 22/11/2026 (voir « Offres
   fictives retirées » plus haut) : il confirme que la saisie manuelle
   fonctionne mais demande un entretien régulier (les offres réelles
   expirent en quelques semaines). Décision à prendre : qui, côté NexRaiz,
   est responsable de cet entretien régulier via `opportunites-admin.php` —
   ou engager une démarche commerciale avec Educarrière (contact trouvé :
   ci@educarriere.net) pour un accès API/flux officiel.
8. **Adresse d'expédition des candidatures « via NexRaiz »**
   (`NEXRAIZ_MAIL_FROM` dans `includes/env.local.php`) : à configurer avec
   une vraie adresse dont NexRaiz contrôle le SPF/DKIM (ex.
   `candidatures@nexraiz.ci`) avant la mise en production, pour une bonne
   délivrabilité chez les recruteurs (Gmail/Outlook en particulier).

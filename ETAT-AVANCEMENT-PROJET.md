# État d'avancement technique — NexRaiz
### Annexe à l'ordre du jour de la réunion du 09/12/2026

Ce document complète le cahier des charges en comparant ce qui est décrit avec ce qui
existe réellement aujourd'hui dans le code (`c:\xampp\htdocs\nexraiz_phase`), à la date du
14/09/2026. Il reprend la numérotation des sections du document d'origine pour faciliter
le rapprochement en réunion.

Légende : ✅ Fait · 🟡 Partiel · ❌ Pas fait

---

## 1. Vue d'ensemble

Le cœur fonctionnel des quatre parcours candidat (Classic, Assistance, Booster,
Wellbeing) est développé : inscription, qualification, consentement, rendez-vous,
paiement, tableau de bord, messagerie, dépôt/consultation de documents, livrables. Les
espaces consultant correspondants existent aussi pour les quatre packs.

Ce qui manque se concentre sur trois zones : **la sécurité du compte/de la session**,
**l'espace administrateur transverse** (aujourd'hui partiel et cloisonné pack par pack),
et les **statuts de dossier normalisés** décrits en section 3 du cahier des charges.

---

## 2. Périmètre fonctionnel

### Espace candidat — ✅ globalement fait
Compte, choix du pack, qualification, consentement, rendez-vous, paiement, tableau de
bord, suivi, messagerie, documents, livrables, reçus : tout est présent pour les 4 packs.
Manque : les notifications (voir section 6 plus bas) et la réinitialisation de mot de
passe (voir section 7).

### Espace consultant — ✅ globalement fait
Connexion, dossiers affectés, infos candidat, réponses au questionnaire, rendez-vous,
notes de session, plan d'action, points de vigilance, dépôt de documents/livrables,
messagerie, changement de statut, confirmation manuelle des virements : tout est
implémenté, mais **dupliqué par pack** (`consultant-dossiers-classic.php`,
`-booster.php`, `-wellbeing.php`, `-assistance.php` répètent chacun leur propre logique
plutôt que de passer par un module commun).

### Espace administrateur — 🟡 partiel
Fait :
- Créer un consultant/admin, changer son rôle, son pack, l'activer/le suspendre
  (`admin-utilisateurs.php`).
- Un tableau de bord chiffré réel (`super-admin-dashboard.php`) : nombre de dossiers,
  chiffre d'affaires, clients/consultants actifs, fil d'activité.
- ✅ *(ajouté le 14/09/2026)* **`admin-dossiers.php`** : tous les dossiers tous packs
  confondus (ou limité au pack de rattachement pour un administrateur de pack), avec
  recherche, filtres et **affectation centralisée d'un consultant** à un dossier.
- ✅ *(ajouté le 14/09/2026)* **`admin-paiements.php`** : tous les paiements, filtres par
  pack/statut/méthode, **confirmation centralisée des virements** et accès direct au
  justificatif déposé par le client.
- ✅ *(ajouté le 14/09/2026)* **`admin-rendezvous.php`** : tous les rendez-vous, filtres,
  annulation avec notification automatique du candidat.
- Les trois nouvelles pages sont accessibles depuis la sidebar admin et le tableau de
  bord (`admin-dashboard.php`).

Pas fait ou incomplet :
- ❌ **Suppression** d'un utilisateur (seule la suspension existe).
- ❌ Gestion des **candidats** par l'admin — le code indique explicitement que les
  comptes clients restent en libre-service uniquement.
- ❌ Pas de gestion fine des **droits d'accès** (seulement un changement de rôle global).
- 🟡 « Journaux d'activité » existants (`activites`) ne concernent que l'activité des
  dossiers ; un vrai journal de sécurité des connexions existe désormais séparément
  (table `login_attempts`, voir section 7 mise à jour ci-dessous).
- 🟡 Paramétrage de contenu : `actualites`, `ressources`, `modeles_documents`,
  `formations` existent en base et ont des pages, mais il n'y a pas de page de réglages
  généraux (textes du site, CGU, coordonnées, etc.).
- ✅ *(corrigé le 14/09/2026)* `admin-dashboard.php` est en réalité la page réelle,
  branchée sur la base et protégée par `require_role(['super_admin'], ...)` — l'audit
  initial s'était trompu de fichier. C'est `super-admin-dashboard.php` (une ancienne
  maquette à données factices, non reliée depuis aucun menu) qui n'avait aucun contrôle
  d'accès ; elle est maintenant protégée par le même `require_role()`.

---

## 3. Statuts de dossier — 🟡 affichage ajouté, schéma inchangé

Le cahier des charges recommande 11 statuts (Inscription commencée → Dossier suspendu ou
annulé). En base, `dossiers.statut` n'a toujours que 3 valeurs (`en_cours`, `en_attente`,
`cloture`) : on a choisi de **ne pas réécrire ce schéma** (trop de pages en dépendent,
risque de régression élevé pour un bénéfice principalement visuel) et à la place d'ajouter
une fonction d'affichage (`nx_statut_detail()` dans `admin-dossiers.php`) qui **calcule**
lequel des 11 statuts décrit le mieux un dossier, à partir de `statut` + `current_step` +
dernier paiement + consultant affecté + livrables déposés. Elle est utilisée dans la
colonne « Statut » de la nouvelle page `admin-dossiers.php`.

C'est une approximation lisible, pas une normalisation réelle de la donnée : si vous
voulez que ces 11 statuts deviennent la source de vérité (stockés, filtrables partout,
visibles aussi côté candidat), c'est un chantier plus large à part entière — modifier
l'ENUM et tous les endroits qui lisent `dossiers.statut` aujourd'hui.

---

## 5. Base de données

La base couvre déjà largement les domaines listés dans le cahier des charges
(utilisateurs, packs, dossiers, rendez-vous, paiements, messagerie, documents,
ressources), avec les données propres à chaque pack. Manquants par rapport au tableau du
cahier des charges :
- ✅ *(ajouté le 14/09/2026)* Table **notifications** (type, titre, corps, lien, lu),
  avec une cloche affichée dans l'en-tête pour tout utilisateur connecté et une page
  `notifications.php` listant l'historique. Déclenchée aujourd'hui sur : paiement
  confirmé, changement de statut de dossier par le consultant, annulation d'un
  rendez-vous par l'admin. Pas de préférences de canal (email/in-app) configurables par
  l'utilisateur — hors périmètre pour l'instant.
- ✅ *(ajouté le 14/09/2026)* Colonnes `justificatif_nom` / `justificatif_chemin` /
  `justificatif_taille_ko` sur `paiements`, pour le dépôt de preuve de virement (voir
  section 9).
- ✅ *(ajouté le 14/09/2026)* Tables de **sécurité** `login_attempts` (journal des
  connexions réussies/échouées, avec verrouillage temporaire) et `password_resets`
  (jetons de réinitialisation). `activites` reste dédiée à l'activité métier du dossier.
- 🟡 Statuts de dossier normalisés (voir section 3 ci-dessus — affichage ajouté, pas de
  changement de schéma).
- 🟡 Table de **permissions** — pas de table dédiée, uniquement l'ENUM de rôle sur
  `users`.

---

## 7. Authentification et rôles — mise à jour du 14/09/2026

| Mesure recommandée | État | Détail |
|---|---|---|
| Hachage sécurisé des mots de passe | ✅ | `password_hash()` / `password_verify()` dans `includes/auth.php` |
| Réinitialisation par lien temporaire | ✅ *(nouveau)* | `mot-de-passe-oublie.php` + `reinitialiser-mot-de-passe.php`, jetons à usage unique valables 1h (`includes/password-reset.php`, table `password_resets`) |
| Limitation des tentatives de connexion | ✅ *(nouveau)* | `attempt_login()` verrouille après 5 échecs / 15 min pour un email donné (table `login_attempts`) |
| Fermeture des sessions inactives | ✅ *(nouveau)* | Déconnexion automatique après 30 min d'inactivité (`nx_enforce_session_timeout()`) |
| Régénération de session à la connexion | ✅ *(nouveau)* | `session_regenerate_id(true)` dans `login_user()` |
| Cookie de session durci | ✅ *(nouveau)* | `httponly`, `samesite=Lax`, `secure` auto si HTTPS (`nx_session_start()`) |
| Protection CSRF des formulaires | ✅ *(nouveau)* | `includes/csrf.php` + token appliqué aux ~47 formulaires du site (96 formulaires comptés au total avec ceux générés en boucle), vérifié par `php -l` sur tous les fichiers |
| Contrôle des autorisations par page | ✅ | Bien fait sur toutes les pages consultant/admin via `require_role()` ; l'ancienne maquette `super-admin-dashboard.php` (non liée, données factices) vient aussi d'être protégée |
| Historique des connexions sensibles | ✅ *(nouveau)* | Table `login_attempts` (email, IP, succès/échec, horodatage) |
| Sauvegardes automatiques | ❌ | Toujours à faire — hors du périmètre code (dépend de l'hébergeur) |
| HTTPS obligatoire | 🟡 *(nouveau)* | `nx_enforce_https()` prêt, activable en production via la variable d'environnement `NEXRAIZ_FORCE_HTTPS` (désactivé par défaut pour ne pas casser XAMPP en local) |

Reste à faire hors code : sauvegardes automatiques programmées côté hébergeur, et
activer `NEXRAIZ_FORCE_HTTPS=1` + certificat TLS au moment du déploiement.

---

## 8. Messagerie candidat-consultant — ✅ fait

Conversation par dossier, pièces jointes, horodatage, statut lu/non lu, badges, historique
complet : tout est présent (`messages`, `messages.php`, `includes/message-attachments.php`).
La messagerie interne entre membres de l'équipe (au-delà du périmètre du cahier des
charges mais utile) est également faite (`messages_internes`, `admin-messages.php`).

Non prévu explicitement dans le cahier des charges mais déjà présent : un système d'appel
vocal WebRTC (`appels`, `includes/webrtc-call.php`, `call-signal.php`).

---

## 9. Paiements

| Élément | État | Détail |
|---|---|---|
| Stripe (carte) | ✅ | `includes/payments.php`, webhook signé (`stripe-webhook.php`) |
| CinetPay (mobile money) | ✅ | Création de paiement + notification (`cinetpay-notify.php`) |
| Virement avec validation manuelle | ✅ *(nouveau)* | Confirmation toujours possible côté consultant Classic, et désormais **centralisée pour l'admin** dans `admin-paiements.php` (tous packs, ou pack de rattachement) |
| Dépôt de la preuve de virement par le candidat | ✅ *(nouveau)* | Zone de dépôt (PDF/JPG/PNG, 10 Mo max) sur les 4 tableaux de bord client quand un virement est en attente ; visible et téléchargeable depuis `admin-paiements.php` |
| Génération de reçu | ✅ | `recu-paiement.php` |
| Confirmation envoyée au candidat et à l'admin | ✅ *(nouveau)* | Notification in-app (cloche) + email best-effort dès qu'un paiement est confirmé (`marquer_paiement_paye()`) |
| Aucune donnée bancaire sensible stockée en base | ✅ | Seules les références externes Stripe/CinetPay sont stockées |
| ⚠️ Point de vigilance | ✅ *(corrigé)* | La clé Stripe codée en dur dans `includes/payment-config.php` a été retirée le 14/09/2026 ; les clés doivent maintenant être fournies via les variables d'environnement `STRIPE_SECRET_KEY` / `STRIPE_PUBLISHABLE_KEY` |

---

## 6 (nouveau). Notifications — 🟡 fait pour les événements principaux

Le cahier des charges prévoit des notifications au candidat (nouveaux messages,
changements de statut, confirmation de paiement, rappels de rendez-vous). Une cloche de
notification (table `notifications`, `includes/notifications.php`) est désormais affichée
dans l'en-tête de toutes les pages candidat, avec une page `notifications.php` listant
l'historique complet. Déclenchée aujourd'hui sur :
- paiement confirmé (in-app + email best-effort) ;
- changement de statut d'un dossier par le consultant ;
- annulation d'un rendez-vous par l'administration.

Les nouveaux messages ont déjà leur propre badge de non-lu (section 8, existant avant
cette session) — pas dupliqué dans la cloche générique. Manque encore : les rappels de
rendez-vous à venir (nécessiterait une tâche planifiée/cron, hors périmètre de cette
session) et des préférences de canal par utilisateur.

---

## Synthèse — ce qui reste à faire avant la mise en production

Priorité haute (conditionne la mise en ligne, section 10 du cahier des charges) :
1. ✅ Protection CSRF sur tous les formulaires — fait le 14/09/2026 (infrastructure +
   token sur les ~47 formulaires du site, `php -l` revérifié sur tous les fichiers).
2. ✅ Limitation des tentatives de connexion + verrouillage temporaire — fait le 14/09/2026.
3. ✅ Fermeture automatique des sessions inactives + régénération de session à la
   connexion — fait le 14/09/2026.
4. ✅ Réinitialisation de mot de passe par lien temporaire — fait le 14/09/2026
   (l'envoi d'email utilise `mail()` en best-effort ; prévoir un vrai relais SMTP en
   production, voir `includes/mailer.php`).
5. ✅ Contrôle d'accès sur l'ancienne maquette `super-admin-dashboard.php` — corrigé le
   14/09/2026 (`admin-dashboard.php`, la page réellement utilisée, était déjà protégée).
6. ✅ Clé Stripe sortie du code vers la configuration serveur — fait le 14/09/2026.
7. 🟡 Forcer HTTPS — le mécanisme est prêt (`nx_enforce_https()`), à activer via
   `NEXRAIZ_FORCE_HTTPS=1` au moment du déploiement en production (nécessite un
   certificat TLS sur l'hébergement).

Priorité moyenne (périmètre fonctionnel attendu par le cahier des charges) :
8. ✅ Pages admin transverses — fait le 14/09/2026 : `admin-dossiers.php` (tous les
   dossiers + affectation consultant centralisée), `admin-paiements.php` (tous les
   paiements + confirmation de virement centralisée), `admin-rendezvous.php` (tous les
   rendez-vous). Requêtes SQL revérifiées directement sur la base locale.
9. ✅ Dépôt d'un justificatif de virement par le candidat — fait le 14/09/2026, sur les
   4 tableaux de bord client (Assistance, Classic, Booster, Wellbeing).
10. ✅ Notifications — fait le 14/09/2026 pour paiement confirmé, changement de statut,
    annulation de rendez-vous (voir section 6). Rappels de rendez-vous programmés non
    couverts (nécessite un cron).
11. ✅ Journal de sécurité des connexions — fait (table `login_attempts`, voir section 7).
12. 🟡 Statuts de dossier : un affichage dérivé des 11 états a été ajouté dans
    `admin-dossiers.php` (voir section 3) ; le schéma lui-même (`dossiers.statut`) n'a
    volontairement pas été changé pour limiter le risque de régression — à rouvrir en
    chantier dédié si vous voulez que ces statuts deviennent la donnée de référence.

Priorité basse / amélioration continue :
13. Suppression d'utilisateur (au-delà de la suspension déjà en place).
14. Page de paramétrage général du contenu du site.
15. Sauvegardes automatiques programmées et documentées (section 10 : condition de mise
    en production).
16. Mutualiser le code dupliqué entre les 4 espaces consultant pack par pack.
17. ✅ *(trouvé et corrigé le 14/09/2026)* `database/schema.sql` n'était pas rejouable
    sur une base déjà initialisée : la ligne `ALTER TABLE users ADD CONSTRAINT
    fk_users_pack...` échouait avec « Duplicate key » si la contrainte existait déjà
    (pas de `IF NOT EXISTS` sur cette ligne, contrairement aux `CREATE TABLE`). Le
    script vérifie maintenant l'existence de la contrainte avant de l'ajouter ; il a été
    revérifié en le rejouant sur la base locale sans erreur.

---

*Analyse technique réalisée par lecture du code source à la demande de l'équipe, en
complément du cahier des charges — à valider/amender en réunion.*

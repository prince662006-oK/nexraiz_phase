<?php
require __DIR__ . '/includes/bootstrap.php';
$utilisateurAccueil = current_user();
$nbNotifAccueil = $utilisateurAccueil ? notifications_non_lues((int) $utilisateurAccueil['id']) : 0;
?>
<!doctype html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>NexRaiz — Votre prochain chapitre commence ici</title>
        <meta name="description" content="Emploi, évolution, difficultés au travail, équilibre : découvrez les accompagnements NexRaiz pour votre vie professionnelle.">
        <link rel="icon" href="uploads/LOGO0.png" type="image/png">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
        <link rel="stylesheet" href="marquete/style.css">
    </head>
    <body>
        <header>
            <a class="brand" href="index.php">
                <img src="marquete/logo.png" alt="NexRaiz" width="170" height="61">
                <small>PARTICULIERS</small>
            </a>
            <nav aria-label="Navigation principale">
                <a href="#accompagnements">Nos accompagnements</a>
                <a href="#approche">Notre approche</a>
                <a href="#questions">Vos questions</a>
                <?php if (!$utilisateurAccueil): ?>
                <a href="mon-espace.php">Mon espace</a>
                <?php endif; ?>
            </nav>
            <?php if ($utilisateurAccueil): ?>
            <div class="nx-account" id="nxAccountWrapper">
                <a href="notifications.php" class="nx-account-bell" aria-label="Notifications">
                    <span class="material-symbols-outlined">notifications</span>
                    <?php if ($nbNotifAccueil > 0): ?>
                    <span class="nx-account-badge"><?php echo $nbNotifAccueil > 9 ? '9+' : $nbNotifAccueil; ?></span>
                    <?php endif; ?>
                </a>
                <button type="button" class="nx-account-avatar" id="nxAccountToggle"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($utilisateurAccueil['prenom'], 0, 1) . mb_substr($utilisateurAccueil['nom'], 0, 1))); ?></button>
                <div class="nx-account-menu" id="nxAccountMenu">
                    <a href="mon-espace.php">Mon espace</a>
                    <a href="parametres.php">Paramètres</a>
                    <a href="logout.php">Déconnexion</a>
                </div>
            </div>
            <?php else: ?>
            <button class="btn small" onclick="startQuiz()">
                Trouver mon parcours <span>↗</span>
            </button>
            <?php endif; ?>
        </header>
        <main>
             <section class="hero">
                <div class="hero-copy">
                    <div class="eyebrow">
                        <span></span>
                        GRANDIR, À VOTRE RYTHME
                    </div>
                    <h1>
                        Votre prochain<br>
                        chapitre<br>
                        commence <em>ici.</em>
                    </h1>
                    <p>Un emploi à trouver. Une envie d’évoluer. Le besoin de souffler. Quel que soit votre moment de vie professionnelle, avançons ensemble.</p>
                    <button class="btn" onclick="startQuiz()">
                        Trouver mon accompagnement <span>↗</span>
                    </button>
                    <a class="quiet" href="#accompagnements">
                        Découvrir les 5 packs <span>↓</span>
                    </a>
                    <div class="hero-bottom">
                        <span>À votre écoute</span>
                        <i>✦</i>
                        <span>À chaque étape</span>
                        <i>✦</i>
                        <span>Avec vous</span>
                    </div>
                </div>
                <div class="hero-visual">
                    <img src="marquete/hero.jpg" alt="Une professionnelle souriante devant son ordinateur" width="900" height="1100">
                    <div class="photo-label">VOTRE PARCOURS. VOS POSSIBILITÉS.</div>
                    <div class="floating">
                        <span class="sun">✳</span>
                        <div>
                            Vous n’avez pas à<br>
                            <strong>tout faire seul.</strong>
                        </div>
                    </div>
                </div>
            </section>
            <section class="needs" id="besoins">
                <div>
                    <span class="eyebrow">PARTONS DE VOUS</span>
                    <h2>
                        De quoi avez-vous<br>besoin aujourd’hui ?
                    </h2>
                </div>
                <div class="need-grid">
                    <button onclick="selectPack(0)">
                        <span>01</span>
                        Apaiser une difficulté au travail<b>↗</b>
                    </button>
                    <button onclick="selectPack(1)">
                        <span>02</span>
                        Trouver mon prochain emploi<b>↗</b>
                    </button>
                    <button onclick="selectPack(2)">
                        <span>03</span>
                        Faire évoluer ma carrière<b>↗</b>
                    </button>
                    <button onclick="selectPack(3)">
                        <span>04</span>
                        Retrouver mon équilibre<b>↗</b>
                    </button>
                </div>
            </section>
            <section class="packs section" id="accompagnements">
                <div class="section-heading">
                    <div>
                        <div class="eyebrow">LES ACCOMPAGNEMENTS NEXRAIZ</div>
                        <h2>
                            À chaque besoin,<br>un nouvel élan.
                        </h2>
                    </div>
                    <p>Des solutions concrètes pour vous aider à avancer, là où vous en êtes.</p>
                </div>
                <div class="pack-grid" id="packGrid"></div>
                <div class="premium">
                    <div>
                        <span class="eyebrow">PACK PREMIUM RH</span>
                        <h3>
                            Toutes les dimensions de votre parcours.<br>Un même accompagnement.
                        </h3>
                        <p>Assistance, emploi, évolution et équilibre : les quatre packs réunis.</p>
                    </div>
                    <button class="btn light" onclick="showPack(4)">Explorer Premium RH ↗</button>
                </div>
            </section>
            <section class="approach section" id="approche">
                <div>
                    <div class="eyebrow">UNE RELATION, AVANT TOUT</div>
                    <h2>
                        Vous écouter.<br>
                        Comprendre.<br>
                        <em>Avancer ensemble.</em>
                    </h2>
                    <p>Votre parcours est unique. Notre point de départ, c’est vous : votre situation, vos aspirations et ce qui compte aujourd’hui.</p>
                </div>
                <ol>
                    <li>
                        <span>01</span>
                        <div>
                            <h3>On fait le point</h3>
                            <p>Vous partagez votre situation et vos attentes pour identifier vos priorités.</p>
                        </div>
                    </li>
                    <li>
                        <span>02</span>
                        <div>
                            <h3>On trace votre chemin</h3>
                            <p>Nous définissons un accompagnement et des actions adaptés à votre objectif.</p>
                        </div>
                    </li>
                    <li>
                        <span>03</span>
                        <div>
                            <h3>Vous avancez, accompagné</h3>
                            <p>Des échanges, des outils et des étapes concrètes pour soutenir votre progression.</p>
                        </div>
                    </li>
                </ol>
            </section>
            <section class="faq section" id="questions">
                <div>
                    <div class="eyebrow">EN TOUTE SIMPLICITÉ</div>
                    <h2>
                        Vous vous posez<br>des questions ?
                    </h2>
                </div>
                <div>
                    <details>
                        <summary>Je ne sais pas quel pack choisir.</summary>
                        <p>Le questionnaire d’orientation vous propose un premier repère à partir de votre besoin. Vous pouvez aussi consulter le détail de chaque pack.</p>
                    </details>
                    <details>
                        <summary>Ces offres sont-elles réservées aux salariés ?</summary>
                        <p>Les accompagnements concernent aussi les personnes en recherche d’emploi, les jeunes diplômés et celles qui préparent une évolution ou une reconversion.</p>
                    </details>
                    <details>
                        <summary>Que comprend le pack Premium RH ?</summary>
                        <p>Il combine Assistance RH, Classic RH, Booster RH et Wellbeing pour aborder ensemble les différentes dimensions de votre vie professionnelle.</p>
                    </details>
                    <details>
                        <summary>Où trouver les tarifs et les modalités ?</summary>
                        <p>Ils seront précisés à l’ouverture des inscriptions. Cette première présentation permet de découvrir les offres et d’identifier votre besoin.</p>
                    </details>
                    <details>
                        <summary>Wellbeing propose-t-il un diagnostic médical ?</summary>
                        <p>Le parcours présenté porte sur le bien-être professionnel. Le questionnaire d’orientation ne constitue pas un diagnostic médical.</p>
                    </details>
                </div>
            </section>
            <section class="closing">
                <span class="eyebrow">LE PREMIER PAS PEUT ÊTRE SIMPLE</span>
                <h2>
                    Et si on parlait<br>
                    de <em>votre avenir ?</em>
                </h2>
                <button class="btn" onclick="startQuiz()">Je trouve mon parcours ↗</button>
                <p>Quelques questions. Un premier repère. À votre rythme.</p>
            </section>
        </main>
        <footer>
            <a class="brand" href="index.php">
                <img src="marquete/logo-white.png" alt="NexRaiz" width="190" height="68">
                <small>LE CAPITAL HUMAIN, AU CŒUR.</small>
            </a>
            <p>
                Structurer. Sécuriser. Développer.<br>Abidjan · Côte d’Ivoire
            </p>
            <a class="footer-account" href="mon-espace.php">Mon espace ↗</a>
            <a href="#accompagnements">Découvrir les accompagnements ↑</a>
            <div class="footnote">
                © 2026 NexRaiz <span>Version de présentation · Inscriptions prochainement</span>
            </div>
        </footer>
        <dialog id="modal">
            <button class="close" onclick="modal.close()" aria-label="Fermer">×</button>
            <div id="modalBody"></div>
        </dialog>
        <script src="marquete/app.js"></script>
        <?php if ($utilisateurAccueil): ?>
        <script>
        (function () {
            var toggle = document.getElementById('nxAccountToggle');
            var menu = document.getElementById('nxAccountMenu');
            var wrapper = document.getElementById('nxAccountWrapper');
            if (!toggle || !menu || !wrapper) return;
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                menu.classList.toggle('is-open');
            });
            document.addEventListener('click', function (e) {
                if (!wrapper.contains(e.target)) menu.classList.remove('is-open');
            });
        })();
        </script>
        <?php endif; ?>
        <?php render_chatbot_widget(); ?>
    </body>
</html>

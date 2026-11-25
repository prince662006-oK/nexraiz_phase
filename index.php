<?php
require __DIR__ . '/includes/bootstrap.php';
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
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "primary-container": "#14304f", "background": "#faf8ff", "outline": "#737686", "inverse-on-surface": "#eef0ff", "on-secondary": "#ffffff", "on-primary-fixed": "#00174b", "surface-container-high": "#e2e7ff", "on-error": "#ffffff", "on-tertiary-container": "#ffede1", "on-secondary-container": "#58657b", "surface-container-low": "#f2f3ff", "inverse-primary": "#b4c5ff", "secondary-fixed": "#d6e3fe", "on-secondary-fixed-variant": "#3a475c", "on-tertiary-fixed-variant": "#6e3900", "tertiary-fixed-dim": "#ffb77d", "on-primary-container": "#eeefff", "error-container": "#ffdad6", "on-primary": "#ffffff", "on-secondary-fixed": "#0e1c2f", "surface-tint": "#1E3E62", "secondary-fixed-dim": "#bac7e1", "tertiary-container": "#a65900", "tertiary-fixed": "#ffdcc3", "on-background": "#131b2e", "on-tertiary": "#ffffff", "surface-bright": "#faf8ff", "on-error-container": "#93000a", "surface-container": "#eaedff", "primary-fixed-dim": "#b4c5ff", "outline-variant": "#c3c6d7", "primary": "#1E3E62", "surface-container-highest": "#dae2fd", "surface-dim": "#d2d9f4", "tertiary": "#824500", "error": "#ba1a1a", "on-primary-fixed-variant": "#003ea8", "surface-variant": "#dae2fd", "surface-container-lowest": "#ffffff", "surface": "#faf8ff", "secondary": "#525f75", "on-tertiary-fixed": "#2f1500", "primary-fixed": "#dbe1ff", "secondary-container": "#d6e3fe", "on-surface-variant": "#434655", "on-surface": "#131b2e", "inverse-surface": "#0B192C" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-2xs": "0.25rem", "space-3xl": "4.5rem", "max-width-content": "80rem", "space-sm": "0.75rem", "space-xl": "2rem", "gutter-mobile": "1rem", "space-xs": "0.5rem", "space-4xl": "6rem", "space-2xl": "3rem", "space-md": "1rem", "max-width-reading": "45rem", "gutter-desktop": "1.5rem", "space-lg": "1.5rem" }, "fontFamily": { "headline-xl": ["Plus Jakarta Sans"], "headline-md": ["Plus Jakarta Sans"], "headline-lg": ["Plus Jakarta Sans"], "headline-sm": ["Plus Jakarta Sans"], "label-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "label-md": ["Plus Jakarta Sans"], "body-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Plus Jakarta Sans"], "display-lg-mobile": ["Plus Jakarta Sans"], "body-lg": ["Plus Jakarta Sans"], "display-lg": ["Plus Jakarta Sans"], "label-badge": ["Plus Jakarta Sans"] }, "fontSize": { "headline-xl": ["2.25rem", { "lineHeight": "2.75rem", "letterSpacing": "-0.02em", "fontWeight": "700" }], "headline-md": ["1.375rem", { "lineHeight": "1.875rem", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg": ["1.75rem", { "lineHeight": "2.25rem", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-sm": ["1.125rem", { "lineHeight": "1.625rem", "letterSpacing": "-0.005em", "fontWeight": "600" }], "label-lg": ["0.875rem", { "lineHeight": "1.25rem", "letterSpacing": "0.01em", "fontWeight": "600" }], "body-md": ["1rem", { "lineHeight": "1.5rem", "letterSpacing": "0em", "fontWeight": "400" }], "label-md": ["0.75rem", { "lineHeight": "1rem", "letterSpacing": "0.02em", "fontWeight": "600" }], "body-sm": ["0.875rem", { "lineHeight": "1.375rem", "letterSpacing": "0.005em", "fontWeight": "400" }], "headline-xl-mobile": ["1.75rem", { "lineHeight": "2.25rem", "letterSpacing": "-0.015em", "fontWeight": "700" }], "display-lg-mobile": ["2.25rem", { "lineHeight": "2.75rem", "letterSpacing": "-0.025em", "fontWeight": "800" }], "body-lg": ["1.125rem", { "lineHeight": "1.75rem", "letterSpacing": "0em", "fontWeight": "400" }], "display-lg": ["3.5rem", { "lineHeight": "4rem", "letterSpacing": "-0.03em", "fontWeight": "800" }], "label-badge": ["0.6875rem", { "lineHeight": "0.875rem", "letterSpacing": "0.04em", "fontWeight": "700" }] } } } }</script>
        <link rel="stylesheet" href="marquete/style.css">
    </head>
    <body>
        <?php render_top_header('accueil'); ?>
        <main class="pt-16">
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
            var avatarBtn = document.getElementById('avatarMenuBtn');
            var avatarMenu = document.getElementById('avatarMenu');
            var avatarWrapper = document.getElementById('avatarMenuWrapper');
            if (!avatarBtn || !avatarMenu || !avatarWrapper) return;
            avatarBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                avatarMenu.classList.toggle('is-open');
            });
            document.addEventListener('click', function (e) {
                if (!avatarWrapper.contains(e.target)) avatarMenu.classList.remove('is-open');
            });
        })();
        </script>
        <?php endif; ?>
        <?php render_chatbot_widget(); ?>
    </body>
</html>

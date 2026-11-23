const packs = [{
    name: 'Assistance RH',
    tag: 'ÊTRE SOUTENU',
    icon: '☷',
    promise: 'Ne restez plus seul face aux difficultés au travail.',
    items: ['Faire le point sur une situation professionnelle délicate', 'Préparer un échange ou une négociation avec votre employeur', 'Aborder les tensions et les conflits avec davantage de recul'],
    url: 'decouverte.php'
}, {
    name: 'Classic RH',
    tag: 'OUVRIR DES PORTES',
    icon: '↗',
    promise: 'Donnez à votre candidature les moyens de convaincre.',
    items: ['Valoriser votre parcours dans votre CV', 'Améliorer votre profil LinkedIn et vos messages de candidature', 'Préparer vos entretiens de recrutement'],
    url: 'classic-decouverte.php'
}, {
    name: 'Booster RH',
    tag: 'ALLER PLUS LOIN',
    icon: '✧',
    promise: 'Préparez votre prochaine étape professionnelle.',
    items: ['Clarifier votre projet d’évolution ou de mobilité', 'Explorer une reconversion professionnelle', 'Préparer une nouvelle prise de fonction'],
    url: 'booster-decouverte.php'
}, {
    name: 'Wellbeing',
    tag: 'RETROUVER SON SOUFFLE',
    icon: '✳',
    promise: 'Retrouvez de l’équilibre dans votre quotidien professionnel.',
    items: ['Faire le point sur votre équilibre professionnel', 'Identifier vos sources de stress et vos besoins', 'Construire des habitudes favorables à votre bien-être'],
    url: 'wellbeing-decouverte.php'
}, {
    name: 'Premium RH',
    tag: 'UN PARCOURS GLOBAL',
    promise: 'Les quatre accompagnements réunis autour de vous.',
    items: ['Assistance RH : aborder vos difficultés professionnelles', 'Classic RH : renforcer votre candidature', 'Booster RH : préparer votre évolution', 'Wellbeing : soutenir votre équilibre']
}];
const modal = document.getElementById('modal')
  , body = document.getElementById('modalBody');
document.getElementById('packGrid').innerHTML = packs.slice(0, 4).map( (p, i) => `<article class="card"><span class="symbol" aria-hidden="true">${p.icon}</span><div class="tag">${p.tag}</div><h3>${p.name}</h3><p>${p.promise}</p><button onclick="showPack(${i})" aria-label="Découvrir le pack ${p.name}">Explorer ce pack <span>↗</span></button></article>`).join('');
function openModal() {
    if (!modal.open)
        modal.showModal()
}
function showPack(i) {
    if (!Number.isInteger(i) || !packs[i])
        throw new Error('Pack inconnu');
    const p = packs[i];
    const cta = p.url
        ? `<a class="btn" href="${p.url}">Commencer ce parcours ↗</a><button class="back" onclick="startQuiz()">M’aider à choisir mon parcours</button>`
        : `<p class="notice">Tarifs, durée et inscriptions : prochainement disponibles. Cette version vous permet de découvrir les accompagnements.</p><button class="back" onclick="startQuiz()">M’aider à choisir mon parcours</button>`;
    body.innerHTML = `<div class="eyebrow">${p.tag}</div><h2>Pack ${p.name}</h2><p>${p.promise}</p><ul>${p.items.map(x => `<li>${x}</li>`).join('')}</ul>${cta}`;
    openModal();
    return {
        pack: p.name
    }
}
function selectPack(i) {
    showPack(i)
}
let selected = 0;
function startQuiz() {
    body.innerHTML = `<div class="eyebrow">VOTRE POINT DE DÉPART · 1 / 2</div><h2>Qu’aimeriez-vous changer aujourd’hui ?</h2><p>Choisissez ce qui vous correspond le mieux.</p><div class="choices">${['Mieux gérer une difficulté au travail', 'Trouver un emploi ou un stage', 'Évoluer ou changer de voie', 'Retrouver mon équilibre', 'Être accompagné sur plusieurs sujets'].map( (x, i) => `<button onclick="quizStep(${i})">${x} ↗</button>`).join('')}</div><p class="notice">Sans inscription. Vos réponses ne sont pas enregistrées.</p>`;
    openModal()
}
function quizStep(i) {
    selected = i;
    body.innerHTML = `<div class="eyebrow">VOTRE POINT DE DÉPART · 2 / 2</div><h2>Où en êtes-vous ?</h2><div class="choices">${['Je cherche à y voir plus clair', 'Je veux préparer une prochaine étape', 'Je suis prêt à passer à l’action'].map( (x, j) => `<button onclick="quizResult(${j})">${x}</button>`).join('')}</div><button class="back" onclick="startQuiz()">← Revenir à mon besoin</button>`
}
function quizResult(j) {
    const p = packs[selected];
    body.innerHTML = `<div class="result-label">VOTRE PREMIÈRE PISTE</div><h2>Le pack ${p.name}</h2><p>${p.promise}</p><p>${['Commencez par clarifier votre situation et les objectifs que vous souhaitez atteindre.', 'Identifiez les étapes et les outils dont vous avez besoin pour préparer la suite.', 'Appuyez-vous sur un accompagnement pour structurer vos prochaines actions.'][j]}</p><button class="btn" onclick="showPack(${selected})">Découvrir mon accompagnement ↗</button><p class="notice">Cette orientation est un premier repère, sans valeur de diagnostic. Le choix définitif dépend de vos besoins.</p><button class="back" onclick="startQuiz()">Recommencer</button>`
}
modal.addEventListener('click', e => {
    if (e.target === modal) {
        const r = modal.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom)
            modal.close()
    }
}
);
if (document.modelContext?.registerTool) {
    try {
        Promise.resolve(document.modelContext.registerTool({
            name: 'show_accompaniment',
            description: 'Afficher le détail d’un pack NexRaiz dans la fenêtre visible.',
            inputSchema: {
                type: 'object',
                properties: {
                    pack: {
                        type: 'integer',
                        minimum: 0,
                        maximum: 4
                    }
                },
                required: ['pack'],
                additionalProperties: false
            },
            execute: input => showPack(input.pack)
        })).catch( () => {}
        );
    } catch {}
}

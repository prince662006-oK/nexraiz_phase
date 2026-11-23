"""
NexRaiz - Pont PHP -> Python pour l'editeur visuel (Pack Classic).

Contrairement a toute bibliotheque PHP du projet (Dompdf : generation
seulement : smalot/pdfparser : lecture seule), PyMuPDF sait vraiment
supprimer le contenu d'origine dans le flux d'un PDF existant (redaction
reelle, pas juste un recouvrement visuel) avant d'inserer le nouveau texte.
C'est la seule difference avec l'ancienne methode (pdf-lib cote navigateur,
qui ne faisait que dessiner par-dessus) : le texte d'origine disparait
reellement, verifie par extraction de texte apres traitement.

Usage : python pdf_editeur.py chemin_requete.json
Le fichier JSON decrit la source, la sortie et la liste des modifications.
Resultat (stdout) : {"ok": true, ...} ou {"ok": false, "erreur": "..."}
"""
import sys
import json

try:
    import pymupdf
except ImportError as exc:
    print(json.dumps({"ok": False, "erreur": f"PyMuPDF indisponible : {exc}"}))
    sys.exit(1)

# Noms courts des polices "Base-14" reconnues nativement par PyMuPDF (aucun
# fichier de police à fournir), une table par famille choisie dans l'éditeur
# visuel. Comme pour la police par défaut (Helvetica), le cas gras+italique
# retombe sur la variante grasse seule : raccourci déjà en place avant cette
# extension, conservé par cohérence plutôt que de risquer un nom de police
# base-14 gras+italique non supporté par tous les lecteurs PDF.
FONTS_PAR_FAMILLE = {
    "helvetica": {
        (False, False): "helv",
        (True, False): "hebo",
        (False, True): "heit",
        (True, True): "hebo",
    },
    "times": {
        (False, False): "tiro",
        (True, False): "tibo",
        (False, True): "tiit",
        (True, True): "tibo",
    },
    "courier": {
        (False, False): "cour",
        (True, False): "cobo",
        (False, True): "coit",
        (True, True): "cobo",
    },
}


def nom_police(edit):
    famille = FONTS_PAR_FAMILLE.get(str(edit.get("police", "helvetica")), FONTS_PAR_FAMILLE["helvetica"])
    return famille.get((bool(edit.get("gras")), bool(edit.get("italique"))), famille[(False, False)])


def couleur_normalisee(valeurs):
    if not valeurs or len(valeurs) < 3:
        return (0, 0, 0)
    return (valeurs[0] / 255.0, valeurs[1] / 255.0, valeurs[2] / 255.0)


def traiter(requete):
    chemin_source = requete["source"]
    chemin_sortie = requete["sortie"]
    edits = requete.get("edits", [])
    tampon = requete.get("tampon")

    doc = pymupdf.open(chemin_source)

    # --- Etape 1 : rédaction + réécriture de chaque bloc modifié. ---
    pages_touchees = set()
    for edit in edits:
        page = doc[int(edit["page"]) - 1]
        pages_touchees.add(page.number)
        rect = pymupdf.Rect(edit["x0"], edit["y0"], edit["x1"], edit["y1"])
        couleur_fond = couleur_normalisee(edit.get("couleur_fond"))

        # Rédaction réelle : supprime le texte/graphique d'origine dans cette
        # zone au moment de apply_redactions(), contrairement à un rectangle
        # dessiné par-dessus qui laisse le contenu d'origine intact dessous.
        page.add_redact_annot(rect, text="", fill=couleur_fond)

    for num_page in pages_touchees:
        doc[num_page].apply_redactions()

    avertissements = []
    for edit in edits:
        page = doc[int(edit["page"]) - 1]
        rect = pymupdf.Rect(edit["x0"], edit["y0"], edit["x1"], edit["y1"])
        couleur_texte = couleur_normalisee(edit.get("couleur_texte"))
        fontname = nom_police(edit)
        taille = max(5, float(edit.get("taille_police", 11)))
        texte = edit.get("texte", "")
        if texte == "":
            continue
        # insert_textbox gère automatiquement le positionnement dans le
        # rectangle (contrairement à insert_text, qui exige de calculer
        # soi-même le point de la ligne de base) et renvoie une valeur
        # négative si le texte ne rentre pas — dans ce cas, on réduit
        # progressivement la taille plutôt que de perdre le texte
        # silencieusement (ce qui s'est produit lors des premiers essais :
        # un remplacement légèrement plus large que l'original disparaissait
        # sans aucun message).
        taille_effective = taille
        reste = page.insert_textbox(rect, texte, fontsize=taille_effective, fontname=fontname, color=couleur_texte, align=0)
        while reste < 0 and taille_effective > 5:
            taille_effective = round(taille_effective * 0.9, 1)
            reste = page.insert_textbox(rect, texte, fontsize=taille_effective, fontname=fontname, color=couleur_texte, align=0)
        if reste < 0:
            avertissements.append(f"Texte tronqué (zone trop petite) pour « {texte[:40]} ».")
        elif taille_effective != taille:
            avertissements.append(f"Taille réduite de {taille} à {taille_effective} pt pour « {texte[:40]} » (zone trop étroite).")

        if edit.get("souligne"):
            # insert_textbox ne renvoie pas la position exacte de chaque
            # ligne rendue : comme pour les marges de correctif (voir le
            # navigateur, redessinerPatches()), on approxime par un trait
            # unique proche du bas de la zone plutôt que de calculer une
            # mise en page ligne par ligne — ces blocs sont presque toujours
            # des lignes uniques (runs extraits du calque de texte PDF).
            y_trait = rect.y1 - max(1.5, taille_effective * 0.12)
            page.draw_line(
                pymupdf.Point(rect.x0, y_trait),
                pymupdf.Point(rect.x1, y_trait),
                color=couleur_texte, width=max(0.5, taille_effective * 0.05),
            )

    # --- Étape 2 : tampon NexRaiz, uniquement en fin de document. ---
    if tampon and tampon.get("texte"):
        derniere_page = doc[-1]
        largeur, hauteur = derniere_page.rect.width, derniere_page.rect.height
        marge = 36
        hauteur_tampon = 16
        texte_tampon = tampon["texte"]
        largeur_tampon = pymupdf.get_text_length(texte_tampon, fontname="hebo", fontsize=8) + 24
        y_tampon = tampon.get("y", hauteur - marge - hauteur_tampon)
        if y_tampon < marge:
            derniere_page = doc.new_page(width=largeur, height=hauteur)
            y_tampon = hauteur - marge - hauteur_tampon
        x_tampon = largeur - marge - largeur_tampon
        rect_tampon = pymupdf.Rect(x_tampon, y_tampon, x_tampon + largeur_tampon, y_tampon + hauteur_tampon)
        derniere_page.draw_rect(rect_tampon, color=(0x0B / 255, 0x19 / 255, 0x2C / 255), fill=(0x0B / 255, 0x19 / 255, 0x2C / 255))
        derniere_page.insert_textbox(
            rect_tampon, texte_tampon, fontsize=7.5, fontname="hebo",
            color=(0xD9 / 255, 0xB4 / 255, 0x5C / 255), align=1,
        )

    doc.save(chemin_sortie)
    doc.close()
    return {"ok": True, "sortie": chemin_sortie, "avertissements": avertissements}


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "erreur": "Fichier de requête manquant."}))
        sys.exit(1)
    try:
        with open(sys.argv[1], "r", encoding="utf-8") as f:
            requete = json.load(f)
        resultat = traiter(requete)
        print(json.dumps(resultat))
    except Exception as exc:  # noqa: BLE001 - on renvoie toute erreur au PHP appelant, jamais un plantage silencieux.
        print(json.dumps({"ok": False, "erreur": str(exc)}))
        sys.exit(1)


if __name__ == "__main__":
    main()

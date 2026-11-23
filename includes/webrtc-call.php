<?php
/**
 * Widget d'appel vocal WebRTC (navigateur à navigateur), réutilisable sur
 * toute page de messagerie. La signalisation (offre/réponse SDP, candidats
 * ICE) transite par call-signal.php, interrogé par sondage HTTP — le flux
 * audio, une fois la connexion établie, circule directement entre les deux
 * navigateurs (pas de serveur média). Un seul serveur STUN public est
 * utilisé ; sans serveur TURN, un appel entre deux réseaux très restrictifs
 * (pare-feu d'entreprise strict) peut échouer à établir la connexion.
 */
function render_call_widget(int $monId): void
{
    ?>
    <audio id="nxRemoteAudio" autoplay></audio>

    <div id="nxIncomingBanner" hidden class="fixed top-4 left-1/2 -translate-x-1/2 z-50 bg-white rounded-2xl shadow-lg border border-outline-variant p-space-md items-center gap-space-sm w-[22rem] max-w-[92vw]">
      <span class="w-12 h-12 rounded-full bg-primary-container/15 text-primary flex items-center justify-center shrink-0 animate-pulse"><span class="material-symbols-outlined text-[24px]">call</span></span>
      <div class="flex-1 min-w-0">
        <p class="font-label-lg text-label-lg text-on-surface font-bold truncate">Appel entrant</p>
        <p id="nxIncomingNom" class="font-body-sm text-[12px] text-secondary truncate"></p>
      </div>
      <button type="button" onclick="NexRaizCall.refuserAppel()" class="w-10 h-10 rounded-full bg-error text-white flex items-center justify-center shrink-0" title="Refuser"><span class="material-symbols-outlined text-[20px]">call_end</span></button>
      <button type="button" onclick="NexRaizCall.repondreAppel()" class="w-10 h-10 rounded-full bg-green-600 text-white flex items-center justify-center shrink-0" title="Répondre"><span class="material-symbols-outlined text-[20px]">call</span></button>
    </div>

    <div id="nxOutgoingOverlay" hidden class="fixed inset-0 z-50 bg-black/40 items-center justify-center p-space-md">
      <div class="bg-white rounded-2xl shadow-lg p-space-xl flex flex-col items-center gap-space-sm w-[20rem] max-w-[92vw]">
        <span class="w-16 h-16 rounded-full bg-primary-container/15 text-primary flex items-center justify-center animate-pulse"><span class="material-symbols-outlined text-[28px]">call</span></span>
        <p id="nxOutgoingNom" class="font-headline-sm text-headline-sm text-on-surface font-bold"></p>
        <p class="font-body-sm text-body-sm text-secondary">Appel en cours...</p>
        <button type="button" onclick="NexRaizCall.annulerAppel()" class="h-11 px-space-lg rounded-xl bg-error text-white font-label-lg text-label-lg flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">call_end</span>Annuler</button>
      </div>
    </div>

    <div id="nxInCallBar" hidden class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 bg-inverse-surface text-inverse-on-surface rounded-2xl shadow-lg px-space-sm py-space-xs items-center gap-space-sm">
      <span class="material-symbols-outlined text-[20px] text-green-400">call</span>
      <div class="leading-tight">
        <p id="nxInCallNom" class="font-label-md text-label-md font-bold"></p>
        <p id="nxCallDuree" class="font-body-sm text-[11px] text-inverse-on-surface/70">00:00</p>
      </div>
      <button type="button" id="nxMuteBtn" onclick="NexRaizCall.toggleMute()" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition-colors" title="Couper le micro"><span id="nxMuteIcon" class="material-symbols-outlined text-[18px]">mic</span></button>
      <button type="button" onclick="NexRaizCall.raccrocher()" class="w-10 h-10 rounded-full bg-error flex items-center justify-center" title="Raccrocher"><span class="material-symbols-outlined text-[18px]">call_end</span></button>
    </div>

    <script>
    window.NexRaizCall = (function() {
      var pc = null;
      var localStream = null;
      var callId = null;
      var role = null;
      var nomActuel = null;
      var pollSonnerieTimer = null;
      var pollEtatTimer = null;
      var noAnswerTimer = null;
      var dureeTimer = null;
      var dureeSecondes = 0;
      var enAppel = false;
      // Vrai dès qu'un appel (sortant ou entrant) commence à être établi, avant même
      // que callId soit connu (le temps de l'accès au micro et de l'échange initial) :
      // empêche qu'un second appel ne démarre ou ne s'affiche par-dessus le premier.
      var enCoursDeTraitement = false;
      var iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];

      function el(id) { return document.getElementById(id); }

      // Tailwind : la classe utilitaire "flex" (couche "utilities") l'emporte
      // toujours sur [hidden]{display:none} (couche "base"), quel que soit
      // l'attribut hidden. On ne doit donc jamais avoir "flex" ET l'attribut
      // hidden en même temps sur un même élément : ces deux fonctions gèrent
      // les deux ensemble pour que le masquage fonctionne réellement à chaque
      // fois (et pas seulement au premier affichage).
      function afficher(id) {
        var e = el(id);
        e.hidden = false;
        e.classList.add('flex');
      }
      function cacher(id) {
        var e = el(id);
        e.hidden = true;
        e.classList.remove('flex');
      }

      function poster(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch('call-signal.php', { method: 'POST', body: body }).then(function(r) { return r.json(); });
      }

      function formaterDuree(s) {
        var m = Math.floor(s / 60), sec = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
      }

      function demarrerChrono() {
        dureeSecondes = 0;
        el('nxCallDuree').textContent = '00:00';
        dureeTimer = setInterval(function() {
          dureeSecondes++;
          el('nxCallDuree').textContent = formaterDuree(dureeSecondes);
        }, 1000);
      }

      function creerPeerConnection() {
        var p = new RTCPeerConnection({ iceServers: iceServers });
        p.onicecandidate = function(e) {
          if (e.candidate) {
            poster('ice', { call_id: callId, candidate: JSON.stringify(e.candidate) });
          }
        };
        p.ontrack = function(e) {
          el('nxRemoteAudio').srcObject = e.streams[0];
        };
        return p;
      }

      function nettoyer() {
        if (pc) { try { pc.close(); } catch (e) {} pc = null; }
        if (localStream) { localStream.getTracks().forEach(function(t) { t.stop(); }); localStream = null; }
        clearInterval(pollEtatTimer); pollEtatTimer = null;
        clearInterval(dureeTimer); dureeTimer = null;
        clearTimeout(noAnswerTimer); noAnswerTimer = null;
        callId = null;
        role = null;
        nomActuel = null;
        enAppel = false;
        enCoursDeTraitement = false;
        // Masque systématiquement les 3 panneaux, quel que soit l'état d'où l'on vient :
        // évite qu'un appel reste affiché "coincé" si un autre appel (entrant/sortant)
        // s'est chevauché avec celui en cours de fermeture.
        cacher('nxIncomingBanner');
        cacher('nxOutgoingOverlay');
        cacher('nxInCallBar');
        window.__nxAppelEntrant = null;
      }

      function appliquerIceRecus(liste) {
        (liste || []).forEach(function(c) {
          if (!pc) return;
          try { pc.addIceCandidate(JSON.parse(c)); } catch (err) {}
        });
      }

      function surveillerEtat() {
        pollEtatTimer = setInterval(function() {
          if (!callId) return;
          var idCourant = callId;
          poster('etat', { call_id: idCourant }).then(function(res) {
            if (res.erreur || idCourant !== callId) return;
            if (role === 'appelant' && res.answer_sdp && pc && pc.signalingState === 'have-local-offer') {
              pc.setRemoteDescription({ type: 'answer', sdp: res.answer_sdp });
            }
            appliquerIceRecus(res.ice);
            if (res.statut === 'accepte' && !enAppel) {
              enAppel = true;
              clearTimeout(noAnswerTimer);
              cacher('nxOutgoingOverlay');
              el('nxInCallNom').textContent = nomActuel || '';
              afficher('nxInCallBar');
              demarrerChrono();
            }
            if (res.statut === 'refuse' || res.statut === 'termine' || res.statut === 'manque') {
              nettoyer();
              demarrerSonnerie();
            }
          });
        }, 1500);
      }

      function appeler(destinataireId, nomAffiche) {
        if (callId || enCoursDeTraitement) { return; }
        enCoursDeTraitement = true;
        role = 'appelant';
        nomActuel = nomAffiche;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
          localStream = stream;
          pc = creerPeerConnection();
          stream.getTracks().forEach(function(t) { pc.addTrack(t, stream); });
          return pc.createOffer().then(function(offer) {
            return pc.setLocalDescription(offer).then(function() { return offer; });
          });
        }).then(function(offer) {
          return poster('initier', { destinataire_id: destinataireId, offer_sdp: offer.sdp });
        }).then(function(res) {
          if (!res || res.erreur) { alert((res && res.erreur) || "Impossible de lancer l'appel."); nettoyer(); return; }
          callId = res.call_id;
          el('nxOutgoingNom').textContent = nomAffiche;
          afficher('nxOutgoingOverlay');
          surveillerEtat();
          noAnswerTimer = setTimeout(function() {
            if (callId && !enAppel) {
              annulerAppel();
              alert('Pas de réponse.');
            }
          }, 30000);
        }).catch(function() {
          alert("Impossible d'accéder au micro. Vérifiez les autorisations de votre navigateur.");
          nettoyer();
        });
      }

      // Ferme immédiatement l'interface (sans attendre le réseau), puis prévient le
      // serveur en tâche de fond : évite qu'un appel reste affiché "coincé" si la
      // requête réseau est lente, échoue, ou vise un appel déjà terminé côté serveur.
      function annulerAppel() {
        var idEnCours = callId;
        nettoyer();
        if (idEnCours) poster('raccrocher', { call_id: idEnCours });
        demarrerSonnerie();
      }

      function repondreAppel() {
        var appel = window.__nxAppelEntrant;
        if (!appel || callId || enCoursDeTraitement) return;
        enCoursDeTraitement = true;
        role = 'destinataire';
        callId = appel.id;
        nomActuel = appel.prenom + ' ' + appel.nom;
        cacher('nxIncomingBanner');
        window.__nxAppelEntrant = null;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
          localStream = stream;
          pc = creerPeerConnection();
          stream.getTracks().forEach(function(t) { pc.addTrack(t, stream); });
          return pc.setRemoteDescription({ type: 'offer', sdp: appel.offer_sdp }).then(function() {
            return pc.createAnswer();
          }).then(function(answer) {
            return pc.setLocalDescription(answer).then(function() { return answer; });
          });
        }).then(function(answer) {
          return poster('repondre', { call_id: callId, answer_sdp: answer.sdp });
        }).then(function(res) {
          if (!res || !res.ok) {
            alert("Cet appel n'est plus disponible.");
            nettoyer();
            demarrerSonnerie();
            return;
          }
          el('nxInCallNom').textContent = nomActuel;
          afficher('nxInCallBar');
          enAppel = true;
          demarrerChrono();
          surveillerEtat();
        }).catch(function() {
          alert("Impossible d'accéder au micro. Vérifiez les autorisations de votre navigateur.");
          var idRefuse = callId;
          nettoyer();
          if (idRefuse) poster('refuser', { call_id: idRefuse });
          demarrerSonnerie();
        });
      }

      function refuserAppel() {
        var appel = window.__nxAppelEntrant;
        cacher('nxIncomingBanner');
        window.__nxAppelEntrant = null;
        if (appel) poster('refuser', { call_id: appel.id });
      }

      function raccrocher() {
        var idEnCours = callId;
        nettoyer();
        if (idEnCours) poster('raccrocher', { call_id: idEnCours });
        demarrerSonnerie();
      }

      function toggleMute() {
        if (!localStream) return;
        var track = localStream.getAudioTracks()[0];
        if (!track) return;
        track.enabled = !track.enabled;
        el('nxMuteIcon').textContent = track.enabled ? 'mic' : 'mic_off';
        el('nxMuteBtn').classList.toggle('bg-error', !track.enabled);
      }

      function demarrerSonnerie() {
        if (pollSonnerieTimer) return;
        pollSonnerieTimer = setInterval(function() {
          // Occupé (appel sortant en préparation, ou déjà en communication) :
          // on ignore silencieusement toute nouvelle sonnerie, on ne montre jamais
          // deux états d'appel en même temps.
          if (callId || enCoursDeTraitement || window.__nxAppelEntrant) return;
          poster('sonnerie', {}).then(function(res) {
            if (callId || enCoursDeTraitement || window.__nxAppelEntrant) return;
            if (res && res.appel) {
              window.__nxAppelEntrant = res.appel;
              el('nxIncomingNom').textContent = res.appel.prenom + ' ' + res.appel.nom;
              afficher('nxIncomingBanner');
            }
          });
        }, 2500);
      }

      demarrerSonnerie();

      return {
        appeler: appeler,
        annulerAppel: annulerAppel,
        repondreAppel: repondreAppel,
        refuserAppel: refuserAppel,
        raccrocher: raccrocher,
        toggleMute: toggleMute,
      };
    })();
    </script>
    <?php
}

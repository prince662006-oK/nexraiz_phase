package com.nexraiz.app;

import android.content.Intent;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.app.Activity;

/**
 * Écran de démarrage illustré (dégradé bleu, wordmark, slogan, silhouette de
 * ville) affiché avant MainActivity. Le splash screen natif d'Android 12+ ne
 * supporte qu'une couleur unie + une icône, pas une image complète : cette
 * activité dédiée est donc nécessaire pour afficher le vrai design demandé.
 */
public class SplashActivity extends Activity {
    private static final int DUREE_AFFICHAGE_MS = 2200;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_splash);

        new Handler(Looper.getMainLooper()).postDelayed(() -> {
            startActivity(new Intent(SplashActivity.this, MainActivity.class));
            finish();
        }, DUREE_AFFICHAGE_MS);
    }
}

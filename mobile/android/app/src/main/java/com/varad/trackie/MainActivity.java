package com.varad.trackie;

import android.os.Bundle;
import android.webkit.CookieManager;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        registerPlugin(TrackieSystemPlugin.class);   // must come before super.onCreate
        super.onCreate(savedInstanceState);
    }

    /**
     * The WebView keeps cookies in memory and writes them to disk only every
     * ~30 s. Swiping the app away inside that window used to lose a fresh
     * sign-in (or a rotated remember-me cookie) and reopen signed out.
     */
    @Override
    public void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }

    @Override
    public void onStop() {
        super.onStop();
        CookieManager.getInstance().flush();
    }
}

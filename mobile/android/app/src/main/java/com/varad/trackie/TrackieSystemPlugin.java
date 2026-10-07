package com.varad.trackie;

import android.annotation.SuppressLint;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.provider.Settings;
import android.webkit.CookieManager;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Small native helpers the web app can't do itself (window.Capacitor.Plugins.TrackieSystem):
 *   flushCookies()            write the WebView's cookies to disk now
 *   getBatteryStatus()        { ignoring, manufacturer } — is battery optimisation off for Trackie?
 *   requestBatteryExemption() system dialog "Let Trackie run in the background?"
 *   openAppSettings()         Trackie's App info screen (battery, notifications, autostart on some phones)
 */
@CapacitorPlugin(name = "TrackieSystem")
public class TrackieSystemPlugin extends Plugin {

    @PluginMethod
    public void flushCookies(PluginCall call) {
        CookieManager.getInstance().flush();
        call.resolve();
    }

    @PluginMethod
    public void getBatteryStatus(PluginCall call) {
        Context ctx = getContext();
        PowerManager pm = (PowerManager) ctx.getSystemService(Context.POWER_SERVICE);
        boolean ignoring = pm != null && pm.isIgnoringBatteryOptimizations(ctx.getPackageName());
        JSObject ret = new JSObject();
        ret.put("ignoring", ignoring);
        ret.put("manufacturer", Build.MANUFACTURER);
        call.resolve(ret);
    }

    @SuppressLint("BatteryLife")   // sideloaded app; reminders are its core purpose
    @PluginMethod
    public void requestBatteryExemption(PluginCall call) {
        Context ctx = getContext();
        try {
            Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + ctx.getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            ctx.startActivity(i);
        } catch (Exception e) {
            openDetails(ctx);
        }
        call.resolve();
    }

    @PluginMethod
    public void openAppSettings(PluginCall call) {
        openDetails(getContext());
        call.resolve();
    }

    private static void openDetails(Context ctx) {
        Intent i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + ctx.getPackageName()));
        i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        ctx.startActivity(i);
    }
}

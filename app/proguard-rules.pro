# Add project specific ProGuard rules here.
# You can control the set of applied configuration files using the
# proguardFiles setting in build.gradle.
-keep class com.rlabs.lotteryapp.WebAppBridge { *; }
-keepclassmembers class com.rlabs.lotteryapp.WebAppBridge {
    @android.webkit.JavascriptInterface <methods>;
}

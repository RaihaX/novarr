plugins {
    alias(libs.plugins.android.application) apply false
    // AGP 9 compiles Kotlin itself (built-in Kotlin), so the app no longer applies
    // org.jetbrains.kotlin.android. Declaring it here (not applied) puts Kotlin 2.4's Gradle
    // plugin on the build classpath, so AGP's built-in Kotlin uses it instead of the older one it ships with.
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.kotlin.serialization) apply false
}

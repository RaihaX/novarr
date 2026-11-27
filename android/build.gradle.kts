// R8 newer than the one AGP 8.13 bundles: it reads Kotlin 2.4's metadata (the bundled one warned
// "An error occurred when parsing kotlin metadata" on every release build). Google's documented fix;
// drop this pin once AGP is upgraded (AGP 9 bundles a matching R8).
buildscript {
    repositories { google() }
    dependencies { classpath("com.android.tools:r8:9.5.34") }
}

plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.kotlin.serialization) apply false
}

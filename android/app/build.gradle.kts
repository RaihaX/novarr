import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

// Release settings that are not for the repository: a properties file named by
// -Pnovarr.release=/path/to/file (or NOVARR_RELEASE_PROPERTIES) with
// storeFile, storePassword, keyAlias, keyPassword and, optionally,
// defaultServer (an address the connect screen starts with).
val releaseProps = Properties().apply {
    val path = (findProperty("novarr.release") as String?) ?: System.getenv("NOVARR_RELEASE_PROPERTIES")
    if (path != null && file(path).isFile) file(path).inputStream().use { load(it) }
}

android {
    namespace = "studio.visns.novarr"
    compileSdk = 35

    signingConfigs {
        if (releaseProps.getProperty("storeFile") != null) {
            create("release") {
                storeFile = file(releaseProps.getProperty("storeFile"))
                storePassword = releaseProps.getProperty("storePassword")
                keyAlias = releaseProps.getProperty("keyAlias")
                keyPassword = releaseProps.getProperty("keyPassword")
            }
        }
    }

    defaultConfig {
        applicationId = "studio.visns.novarr"
        minSdk = 26
        targetSdk = 35
        versionCode = 18
        versionName = "1.17"
        buildConfigField("String", "DEFAULT_SERVER", "\"" + (releaseProps.getProperty("defaultServer") ?: "") + "\"")
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    testOptions {
        unitTests.isReturnDefaultValues = true
    }

    lint {
        // The library versions are pinned to what compiles against SDK 35 on purpose.
        disable += setOf("GradleDependency", "NewerVersionAvailable", "AndroidGradlePluginVersion", "OldTargetApi")
        // AAPT2 only finds the adaptive launcher icon under mipmap-anydpi-v26, even with minSdk 26.
        disable += "ObsoleteSdkInt"
    }
}

kotlin {
    jvmToolchain(17)
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons.extended)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.process)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.datastore.preferences)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.coil.compose)
    implementation(libs.coil.network.okhttp)
    implementation(libs.media3.exoplayer)
    implementation(libs.media3.session)
    implementation(libs.media3.datasource.okhttp)
    debugImplementation(libs.androidx.compose.ui.tooling)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
}

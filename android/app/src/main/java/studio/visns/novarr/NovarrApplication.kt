package studio.visns.novarr

import android.app.Application
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.ProcessLifecycleOwner
import coil3.ImageLoader
import coil3.PlatformContext
import coil3.SingletonImageLoader
import coil3.disk.DiskCache
import coil3.network.okhttp.OkHttpNetworkFetcherFactory
import kotlinx.coroutines.launch
import okio.Path.Companion.toOkioPath
import studio.visns.novarr.app.AppModel

/** Manual wiring: one AppModel for the process, and Coil set up to use its image client. */
class NovarrApplication : Application(), SingletonImageLoader.Factory {
    lateinit var model: AppModel
        private set

    override fun onCreate() {
        super.onCreate()
        model = AppModel(this)
        ProcessLifecycleOwner.get().lifecycle.addObserver(object : DefaultLifecycleObserver {
            override fun onStart(owner: LifecycleOwner) {
                model.checks.setForeground(true)
                model.scope.launch { model.flushWrites() }
                // A newer APK on the server is noticed without waiting for a relaunch.
                model.refreshMetaIfDue()
                // Back from "Install unknown apps": carry on with a waiting update.
                model.updater.onForeground()
            }

            override fun onStop(owner: LifecycleOwner) {
                model.checks.setForeground(false)
            }
        })
    }

    override fun newImageLoader(context: PlatformContext): ImageLoader =
        ImageLoader.Builder(context)
            // Covers and manga pages are public or pre-signed: no credentials ever go with them.
            .components { add(OkHttpNetworkFetcherFactory(callFactory = { model.imageHttp })) }
            .diskCache {
                DiskCache.Builder()
                    .directory(model.coverCacheDir().toOkioPath())
                    .maxSizeBytes(256L shl 20)
                    .build()
            }
            .build()
}

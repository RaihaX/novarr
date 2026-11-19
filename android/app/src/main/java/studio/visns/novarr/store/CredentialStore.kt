package studio.visns.novarr.store

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import java.io.File
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import kotlinx.serialization.Serializable
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ServerUrl

/** Encrypts small secrets. The app uses the Android Keystore; tests use a fake. */
interface SecretBox {
    fun seal(plain: ByteArray): ByteArray
    fun open(sealed: ByteArray): ByteArray
}

/**
 * AES-256-GCM with a key that lives in the Android Keystore and never leaves
 * it, so the token is never on disk in plain text and a copied file is
 * useless on another device. Output: 12-byte IV followed by the ciphertext.
 */
class KeystoreSecretBox(private val alias: String = "studio.visns.novarr.credentials") : SecretBox {
    private fun key(): SecretKey {
        val store = KeyStore.getInstance(KEYSTORE).apply { load(null) }
        (store.getEntry(alias, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .build(),
        )
        return generator.generateKey()
    }

    override fun seal(plain: ByteArray): ByteArray {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())
        return cipher.iv + cipher.doFinal(plain)
    }

    override fun open(sealed: ByteArray): ByteArray {
        require(sealed.size > IV_SIZE) { "Sealed data too short" }
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, sealed, 0, IV_SIZE))
        return cipher.doFinal(sealed, IV_SIZE, sealed.size - IV_SIZE)
    }

    private companion object {
        const val KEYSTORE = "AndroidKeyStore"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val IV_SIZE = 12
    }
}

/** A token plus who it belongs to, as stored for one server. */
@Serializable
data class Credential(val token: String, val user: AuthUser)

/**
 * Sign-in credentials, one per server, each sealed by a [SecretBox] into its
 * own file keyed by the server's storage key, so two servers never share a
 * token. A file that can't be opened (key lost after a reinstall or restore)
 * counts as "not signed in".
 */
class CredentialStore(private val dir: File, private val box: SecretBox) {
    private fun file(server: String) = File(dir, "${ServerUrl.storageKey(server)}.bin")

    fun credential(server: String): Credential? = runCatching {
        val sealed = file(server).takeIf { it.exists() }?.readBytes() ?: return null
        NovarrJson.decodeFromString(Credential.serializer(), box.open(sealed).decodeToString())
    }.getOrNull()

    fun save(credential: Credential, server: String): Boolean = runCatching {
        dir.mkdirs()
        val plain = NovarrJson.encodeToString(Credential.serializer(), credential).encodeToByteArray()
        atomicWrite(file(server), box.seal(plain))
    }.isSuccess

    fun remove(server: String) {
        file(server).delete()
    }
}

package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Test
import studio.visns.novarr.api.ServerUrl

class ServerUrlTest {
    @Test
    fun addsHttpWhenSchemeMissing() {
        assertEquals("http://192.168.0.10:8080", ServerUrl.normalize("192.168.0.10:8080"))
        assertEquals("http://novarr.local", ServerUrl.normalize("novarr.local"))
    }

    @Test
    fun keepsHttpsAndLowercasesSchemeAndHost() {
        assertEquals("https://box.tail1234.ts.net", ServerUrl.normalize("HTTPS://Box.Tail1234.ts.net/"))
    }

    @Test
    fun trimsWhitespaceSlashesQueryAndFragment() {
        assertEquals("http://10.0.2.2:8773", ServerUrl.normalize("  http://10.0.2.2:8773///?x=1#top \n"))
    }

    @Test
    fun dropsPastedApiSuffix() {
        assertEquals("http://host:8080", ServerUrl.normalize("http://host:8080/api/v1/"))
        assertEquals("http://host:8080", ServerUrl.normalize("http://host:8080/API"))
        assertEquals("http://host/novarr", ServerUrl.normalize("host/novarr/api/v1"))
    }

    @Test
    fun keepsASubpath() {
        assertEquals("http://host/novarr", ServerUrl.normalize("http://host/novarr/"))
    }

    @Test
    fun rejectsJunk() {
        assertNull(ServerUrl.normalize(""))
        assertNull(ServerUrl.normalize("   "))
        assertNull(ServerUrl.normalize("http://"))
        assertNull(ServerUrl.normalize("ftp://host"))
        assertNull(ServerUrl.normalize("my server"))
    }

    @Test
    fun storageKeyIsFilesystemSafeAndPerServer() {
        val key = ServerUrl.storageKey("http://192.168.0.10:8080")
        assertEquals("http_192_168_0_10_8080", key)
        assertNotEquals(key, ServerUrl.storageKey("http://192.168.0.11:8080"))
        assertEquals(ServerUrl.storageKey("http://Host"), ServerUrl.storageKey("http://host"))
    }
}

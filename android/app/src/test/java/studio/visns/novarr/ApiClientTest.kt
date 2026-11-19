package studio.visns.novarr

import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.store.WriteKind

/** Answers every request locally and records it; nothing touches the network. */
class FakeServer(var respond: (Request) -> Pair<Int, String> = { 200 to "{}" }) : Interceptor {
    val requests = mutableListOf<Request>()
    val bodies = mutableListOf<String>()

    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        requests += request
        bodies += request.body?.let { body -> Buffer().also { body.writeTo(it) }.readUtf8() }.orEmpty()
        val (code, body) = respond(request)
        return Response.Builder()
            .request(request)
            .protocol(Protocol.HTTP_1_1)
            .code(code)
            .message("test")
            .body(body.toResponseBody("application/json".toMediaType()))
            .build()
    }

    val client: OkHttpClient get() = OkHttpClient.Builder().addInterceptor(this).build()
}

class ApiClientTest {
    private val device = DeviceIdentity("a_TESTDEVICE123", "Pixel 8")

    @Test
    fun authenticatedRequestsCarryTheBearerToken() = runTest {
        val server = FakeServer { 200 to """{"novels":[]}""" }
        ApiClient("http://host:8080", server.client, device, token = "tok123").novels()
        val request = server.requests.single()
        assertEquals("Bearer tok123", request.header("Authorization"))
        assertEquals("application/json", request.header("Accept"))
        assertEquals("http://host:8080/api/v1/novels?medium=all", request.url.toString())
    }

    @Test
    fun noTokenMeansNoAuthorizationHeader() = runTest {
        val server = FakeServer { 200 to """{"novels":[]}""" }
        ApiClient("http://host", server.client, device, token = "").novels()
        assertNull(server.requests.single().header("Authorization"))
    }

    @Test
    fun publicEndpointsNeverCarryTheToken() = runTest {
        val server = FakeServer { request ->
            if (request.url.encodedPath.endsWith("meta")) 200 to """{"app":"novarr","api_version":1}"""
            else 200 to """{"token":"new","user":{"id":2,"username":"reader","role":"reader"}}"""
        }
        val client = ApiClient("http://host", server.client, device, token = "old")
        client.meta()
        val response = client.signIn("reader", "pw", device)
        assertEquals("new", response.token)
        server.requests.forEach { assertNull(it.header("Authorization")) }
        val body = NovarrJson.parseToJsonElement(server.bodies[1]).jsonObject
        assertEquals("reader", body["username"]?.jsonPrimitive?.content)
        assertEquals("a_TESTDEVICE123", body["device_id"]?.jsonPrimitive?.content)
        assertEquals("Pixel 8", body["device_name"]?.jsonPrimitive?.content)
    }

    @Test
    fun writesCarryDeviceHeadersAndAge() = runTest {
        val server = FakeServer { 200 to """{"success":true}""" }
        val client = ApiClient("http://host", server.client, device, token = "t")
        client.send(WriteKind.Progress(5, 40, false), age = 1234)
        client.send(WriteKind.Progress(5, 100, true), age = 0)
        client.send(WriteKind.SetRead(6, false), age = 10)
        client.send(WriteKind.BulkRead(BulkReadRequest.upTo(9, 2, true)), age = 7)

        server.requests.forEach {
            assertEquals("a_TESTDEVICE123", it.header("X-Novarr-Device"))
            assertEquals("Pixel 8", it.header("X-Novarr-Device-Name"))
            assertEquals("Bearer t", it.header("Authorization"))
            assertEquals("POST", it.method)
        }
        assertEquals("/api/v1/chapters/5/progress", server.requests[0].url.encodedPath)
        assertEquals("""{"progress":40,"age":1234}""", server.bodies[0])
        assertEquals("""{"progress":100,"read":true,"age":0}""", server.bodies[1])
        assertEquals("/api/v1/chapters/6/read", server.requests[2].url.encodedPath)
        assertEquals("""{"read":false,"age":10}""", server.bodies[2])
        assertEquals("/api/v1/chapters/bulk-read", server.requests[3].url.encodedPath)
        val bulk = NovarrJson.parseToJsonElement(server.bodies[3]).jsonObject
        assertEquals("up_to", bulk["scope"]?.jsonPrimitive?.content)
        assertEquals("9", bulk["anchor_id"]?.jsonPrimitive?.content)
        assertEquals("7", bulk["age"]?.jsonPrimitive?.content)
    }

    @Test
    fun unauthorizedReportsTheRejectedTokenOnce() = runTest {
        val server = FakeServer { 401 to """{"message":"Unauthenticated.","code":"unauthenticated"}""" }
        val rejected = mutableListOf<String?>()
        val client = ApiClient("http://host", server.client, device, token = "stale") { rejected += it }
        try {
            client.home()
            fail("expected 401")
        } catch (e: ApiError) {
            assertTrue(e.isUnauthenticated)
            assertFalse(e.isRetryable)
        }
        assertEquals(listOf<String?>("stale"), rejected)
    }

    @Test
    fun unauthorizedWithoutTokenIsReportedToo() = runTest {
        // Sign-in was switched on while the app had no account: the server now asks for one.
        val server = FakeServer { 401 to """{"message":"Unauthenticated."}""" }
        val rejected = mutableListOf<String?>()
        val client = ApiClient("http://host", server.client, device, token = null) { rejected += it }
        runCatching { client.novels() }
        assertEquals(listOf<String?>(null), rejected)
    }

    @Test
    fun signOutAndPublicCallsDoNotReport401() = runTest {
        val server = FakeServer { 401 to "{}" }
        var reported = 0
        val client = ApiClient("http://host", server.client, device, token = "t") { reported++ }
        runCatching { client.signOut() }
        runCatching { client.signIn("a", "b", device) }
        assertEquals(0, reported)
        assertEquals("DELETE", server.requests[0].method)
        assertEquals("Bearer t", server.requests[0].header("Authorization"))
    }

    @Test
    fun forbiddenShowsTheServersMessage() = runTest {
        val server = FakeServer { 403 to """{"message":"Readers can't do that.","code":"forbidden"}""" }
        val error = runCatching { ApiClient("http://host", server.client, device, "t").startCheck(1) }.exceptionOrNull() as ApiError
        assertTrue(error.isForbidden)
        assertEquals("Readers can't do that.", error.userMessage)
    }

    @Test
    fun metaRejectsOtherServers() = runTest {
        val html = FakeServer { 200 to "<html>hi</html>" }
        assertEquals(ApiError.NotNovarr, runCatching { ApiClient("http://host", html.client).meta() }.exceptionOrNull())
        val other = FakeServer { 200 to """{"app":"sonarr","api_version":3}""" }
        assertEquals(ApiError.NotNovarr, runCatching { ApiClient("http://host", other.client).meta() }.exceptionOrNull())
        val old = FakeServer { 200 to """{"app":"novarr","api_version":0}""" }
        assertTrue(runCatching { ApiClient("http://host", old.client).meta() }.exceptionOrNull() is ApiError.ApiTooOld)
    }

    @Test
    fun missingPositionEndpointMeansNoPosition() = runTest {
        val server = FakeServer { 404 to "{}" }
        assertNull(ApiClient("http://host", server.client, device, "t").latestPosition(3))
    }

    @Test
    fun retryRules() {
        assertTrue(ApiError.Unreachable("x").isRetryable)
        assertTrue(ApiError.TimedOut.isRetryable)
        assertTrue(ApiError.Http(503, null).isRetryable)
        assertTrue(ApiError.Http(429, null).isRetryable)
        assertFalse(ApiError.Http(422, null).isRetryable)
        assertFalse(ApiError.Http(404, null).isRetryable)
        assertFalse(ApiError.Http(401, null).isRetryable)
    }
}

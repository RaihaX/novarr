import Foundation
import Testing
@testable import Novarr

// MARK: - Helpers

private func scratchDirectory() -> URL {
    FileManager.default.temporaryDirectory
        .appending(path: "novarr-tests-\(UUID().uuidString)", directoryHint: .isDirectory)
}

private func signInDefaults(server: URL) -> UserDefaults {
    let defaults = UserDefaults(suiteName: "novarr-tests-\(UUID().uuidString)") ?? .standard
    defaults.set(server.absoluteString, forKey: AppModel.serverDefaultsKey)
    defaults.set([ServerURL.storageKey(for: server): true], forKey: AppModel.authRequiredDefaultsKey)
    return defaults
}

@MainActor
private func waitUntil(_ what: Comment, timeout: Duration = .seconds(3),
                       _ condition: @MainActor () async -> Bool) async throws {
    let deadline = ContinuousClock.now + timeout
    while !(await condition()) {
        if ContinuousClock.now > deadline {
            Issue.record("Timed out waiting for \(what)")
            return
        }
        try await Task.sleep(for: .milliseconds(5))
    }
}

/// A counter shared with a stub server's handler.
private final class Counter: @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Int] = [:]
    /// Returns the new count for `key` (1 on the first call).
    func next(_ key: String) -> Int { lock.withLock { values[key, default: 0] += 1; return values[key]! } }
    func count(_ key: String) -> Int { lock.withLock { values[key, default: 0] } }
}

/// Every delay the pairing loop asked to sleep.
private final class Delays: @unchecked Sendable {
    private let lock = NSLock()
    private var _values: [TimeInterval] = []
    func append(_ value: TimeInterval) { lock.withLock { _values.append(value) } }
    var values: [TimeInterval] { lock.withLock { _values } }
}

private func json(_ text: String) -> Data { Data(text.utf8) }

private func bodyJSON(_ data: Data?) throws -> [String: String] {
    let data = try #require(data)
    return try #require(try JSONSerialization.jsonObject(with: data) as? [String: String])
}

private let device = DeviceIdentity(id: "i_testdevice01", name: "iPhone")
private let userJSON = #"{"id":1,"name":"Reyhan","username":"reyhan","role":"admin"}"#
private let tokenBody = json(#"{"token":"tok-new","user":\#(userJSON)}"#)
private let challengeHex = String(repeating: "ab", count: 32)
private let challengeBody = json("""
    {"code":"second_step_required","message":"Enter your sign-in code to finish signing in.",
     "challenge":"\(challengeHex)","expires_in":600,"methods":["totp","email","recovery"],
     "email_hint":"r•••@a•••.com.au"}
    """)
private let unavailableMessage = "This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr."
private let wrongCodeBody = json(#"{"code":"wrong_code","message":"That code is not right. Check it and try again.","tries_left":3}"#)
private let expiredBody = json(#"{"code":"challenge_expired","message":"That took too long. Sign in again."}"#)
private let throttledBody = json(#"{"code":"throttled","message":"Too many attempts. Try again in 60 seconds.","retry_after":60}"#)
private let tooManyWrongBody = json(#"{"code":"challenge_expired","message":"Too many wrong codes. Sign in again."}"#)
private let passkeyOnlyMessage = "This account signs in with a passkey. Link this device with a code instead: on a phone or computer where you are signed in, open Account → Link a device."

private func challenge(_ methods: [SecondStepMethod], hint: String? = nil) -> SecondStepChallenge {
    SecondStepChallenge(challenge: challengeHex, message: nil, expiresIn: 600, methods: methods, emailHint: hint)
}

private let server = URL(string: "https://novarr.example.com")!

// MARK: - Decoding

@Suite("Second step: decoding")
struct SecondStepDecodingTests {
    @Test func metaDefaultsForOlderServers() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"auth":{"required":true,"methods":["password"]}}
            """)
        #expect(meta.auth == ServerAuth(required: true, methods: ["password"], secondStep: false, available: true))
        #expect(meta.signInUnavailable == false)
        #expect(meta.offersPairing == false)
    }

    @Test func metaSecondStepAndAvailability() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"auth":{"required":true,"methods":["password","pairing"],"second_step":true,"available":true}}
            """)
        #expect(meta.auth?.secondStep == true)
        #expect(meta.offersPairing)
        #expect(meta.signInUnavailable == false)

        let closed = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"auth":{"required":true,"methods":["password","pairing"],"second_step":true,"available":false}}
            """)
        #expect(closed.requiresSignIn)
        #expect(closed.signInUnavailable)

        // `available: false` only matters while sign-in is required.
        let open = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"auth":{"required":false,"available":false}}
            """)
        #expect(open.signInUnavailable == false)
        #expect(try Fixture.decode(ServerMeta.self, "meta").signInUnavailable == false)
    }

    @Test func secondStepRequiredBody() throws {
        let body = try JSONCoding.decoder().decode(AuthErrorBody.self, from: challengeBody)
        let challenge = try #require(body.secondStepChallenge)
        #expect(challenge.challenge == challengeHex)
        #expect(challenge.expiresIn == 600)
        #expect(challenge.methods == [.totp, .email, .recovery])
        #expect(challenge.emailHint == "r•••@a•••.com.au")
        #expect(challenge.message == "Enter your sign-in code to finish signing in.")
    }

    @Test func challengeKeepsOnlyUsableMethods() throws {
        let body = try Fixture.decode(AuthErrorBody.self, json: """
            {"code":"second_step_required","challenge":"c1","methods":["passkey","recovery","recovery"],"email_hint":"x"}
            """)
        let challenge = try #require(body.secondStepChallenge)
        #expect(challenge.methods == [.recovery])
        #expect(challenge.emailHint == nil) // only with "email"
        #expect(challenge.expiresIn == 600)  // default when missing

        let none = try Fixture.decode(AuthErrorBody.self, json: #"{"code":"second_step_required","challenge":"c1","methods":["passkey"]}"#)
        #expect(none.secondStepChallenge == nil)
        let noChallenge = try Fixture.decode(AuthErrorBody.self, json: #"{"code":"second_step_required","methods":["totp"]}"#)
        #expect(noChallenge.secondStepChallenge == nil)
        let plain401 = try Fixture.decode(AuthErrorBody.self, json: #"{"message":"Unauthenticated.","code":"unauthenticated"}"#)
        #expect(plain401.secondStepChallenge == nil)
    }

    @Test func errorBodies() throws {
        let unavailable = try Fixture.decode(AuthErrorBody.self, json: """
            {"code":"second_step_unavailable","message":"\(unavailableMessage)"}
            """)
        #expect(unavailable.code == AuthErrorBody.secondStepUnavailable)
        #expect(unavailable.message == unavailableMessage)

        let wrong = try JSONCoding.decoder().decode(AuthErrorBody.self, from: wrongCodeBody)
        #expect(wrong.code == AuthErrorBody.wrongCode)
        #expect(wrong.triesLeft == 3)
        let expired = try JSONCoding.decoder().decode(AuthErrorBody.self, from: expiredBody)
        #expect(expired.code == AuthErrorBody.challengeExpired)
        let throttled = try JSONCoding.decoder().decode(AuthErrorBody.self, from: throttledBody)
        #expect(throttled.code == "throttled")
        // An empty or odd body never fails the decode of the status.
        #expect((try? Fixture.decode(AuthErrorBody.self, json: "{}")) != nil)
    }

    @Test func pairingBodies() throws {
        let start = try Fixture.decode(PairingStart.self, json: #"{"code":"K7M4QX","secret":"s3cr3t","expires_in":600,"interval":3}"#)
        #expect(start == PairingStart(code: "K7M4QX", secret: "s3cr3t", expiresIn: 600, interval: 3))
        #expect(throws: (any Error).self) { try Fixture.decode(PairingStart.self, json: #"{"code":"K7M4QX"}"#) }

        let pending = try Fixture.decode(PairingStatusBody.self, json: #"{"status":"pending"}"#)
        #expect(pending.status == "pending")
        let linked = try Fixture.decode(PairingStatusBody.self, json: #"{"status":"linked","token":"t","user":\#(userJSON)}"#)
        #expect(linked.token == "t")
        #expect(linked.user?.username == "reyhan")
    }
}

// MARK: - API client

@Suite("Second step: API calls")
struct SecondStepClientTests {
    /// A client holding a token, to prove none of the auth calls sends it.
    private func client(_ log: RequestLog, _ answer: @escaping @Sendable (URLRequest) throws -> (Int, Data)) -> APIClient {
        StubURLProtocol.client(token: "tok", onUnauthorized: { _ in Issue.record("auth call reported a 401") }) { request, body in
            log.append(request, body)
            return try answer(request)
        }
    }

    @Test func signInOutcomes() async throws {
        let log = RequestLog()
        let status = Counter()
        let api = client(log) { _ in
            switch status.next("n") {
            case 1: (200, tokenBody)
            case 2: (401, challengeBody)
            case 3: (401, json(#"{"message":"Unauthenticated.","code":"unauthenticated"}"#))
            case 4: (403, json(#"{"code":"second_step_unavailable","message":"\#(unavailableMessage)"}"#))
            case 5: (403, json(#"{"message":"This address is only available once sign-in is turned on."}"#))
            case 6: (422, json(#"{"message":"Wrong name or password."}"#))
            default: (429, throttledBody)
            }
        }
        let signedIn = try await api.signIn(username: "reyhan", password: "pw", device: device)
        #expect(signedIn == .signedIn(AuthTokenResponse(token: "tok-new", user: AuthUser(id: 1, name: "Reyhan", username: "reyhan", role: .admin))))
        let second = try await api.signIn(username: "reyhan", password: "pw", device: device)
        guard case .secondStepRequired(let challenge) = second else {
            Issue.record("expected a challenge, got \(second)")
            return
        }
        #expect(challenge.methods == [.totp, .email, .recovery])
        await #expect(throws: APIError.http(status: 401, message: "Unauthenticated.")) {
            _ = try await api.signIn(username: "reyhan", password: "pw", device: device)
        }
        await #expect(throws: APIError.secondStepUnavailable(unavailableMessage)) {
            _ = try await api.signIn(username: "reyhan", password: "pw", device: device)
        }
        await #expect(throws: APIError.http(status: 403, message: "This address is only available once sign-in is turned on.")) {
            _ = try await api.signIn(username: "reyhan", password: "pw", device: device)
        }
        await #expect(throws: APIError.http(status: 422, message: "Wrong name or password.")) {
            _ = try await api.signIn(username: "reyhan", password: "pw", device: device)
        }
        await #expect(throws: APIError.http(status: 429, message: "Too many attempts. Try again in 60 seconds.")) {
            _ = try await api.signIn(username: "reyhan", password: "pw", device: device)
        }
        for (request, _) in log.requests {
            #expect(request.value(forHTTPHeaderField: "Authorization") == nil)
            #expect(request.url?.path == "/api/v1/auth/token")
        }
    }

    @Test func emailCode() async throws {
        let log = RequestLog()
        let status = Counter()
        let api = client(log) { _ in
            switch status.next("n") {
            case 1: (204, Data())
            case 2: (404, json(#"{"code":"challenge_expired"}"#))
            default: (429, json(#"{"code":"throttled","message":"Too many codes. Try again in 120 seconds.","retry_after":120}"#))
            }
        }
        try await api.requestEmailCode(challenge: challengeHex, device: device)
        await #expect(throws: APIError.challengeExpired(nil)) { try await api.requestEmailCode(challenge: challengeHex, device: device) }
        await #expect(throws: APIError.http(status: 429, message: "Too many codes. Try again in 120 seconds.")) {
            try await api.requestEmailCode(challenge: challengeHex, device: device)
        }

        let first = try #require(log.requests.first)
        #expect(first.request.httpMethod == "POST")
        #expect(first.request.url?.path == "/api/v1/auth/token/email-code")
        #expect(first.request.value(forHTTPHeaderField: "Authorization") == nil)
        #expect(try bodyJSON(first.body) == ["challenge": challengeHex, "device_id": "i_testdevice01"])
    }

    @Test func secondStep() async throws {
        let log = RequestLog()
        let status = Counter()
        let api = client(log) { _ in
            switch status.next("n") {
            case 1: (200, tokenBody)
            case 2: (422, wrongCodeBody)
            case 3: (404, expiredBody)
            default: (429, throttledBody)
            }
        }
        let response = try await api.submitSecondStep(challenge: challengeHex, method: .totp, code: "123456", device: device)
        #expect(response.token == "tok-new")
        await #expect(throws: APIError.wrongCode(message: "That code is not right. Check it and try again.", triesLeft: 3)) {
            _ = try await api.submitSecondStep(challenge: challengeHex, method: .email, code: "000000", device: device)
        }
        await #expect(throws: APIError.challengeExpired("That took too long. Sign in again.")) {
            _ = try await api.submitSecondStep(challenge: challengeHex, method: .recovery, code: "abcde-fghij", device: device)
        }
        await #expect(throws: APIError.http(status: 429, message: "Too many attempts. Try again in 60 seconds.")) {
            _ = try await api.submitSecondStep(challenge: challengeHex, method: .totp, code: "123456", device: device)
        }
        let requests = log.requests
        #expect(requests.allSatisfy { $0.request.url?.path == "/api/v1/auth/token/second-step" })
        #expect(requests.allSatisfy { $0.request.httpMethod == "POST" })
        #expect(requests.allSatisfy { $0.request.value(forHTTPHeaderField: "Authorization") == nil })
        #expect(try bodyJSON(requests[0].body) == ["challenge": challengeHex, "method": "totp", "code": "123456",
                                                   "device_id": "i_testdevice01"])
        #expect(try bodyJSON(requests[1].body)["method"] == "email")
        #expect(try bodyJSON(requests[2].body) == ["challenge": challengeHex, "method": "recovery", "code": "abcde-fghij",
                                                   "device_id": "i_testdevice01"])
    }

    @Test func pairing() async throws {
        let log = RequestLog()
        let status = Counter()
        let api = client(log) { request in
            if request.httpMethod == "POST" {
                return (201, json(#"{"code":"K7M4QX","secret":"s3cr3t","expires_in":600,"interval":3}"#))
            }
            switch status.next("poll") {
            case 1: return (200, json(#"{"status":"pending"}"#))
            case 2: return (200, json(#"{"status":"linked","token":"tok-new","user":\#(userJSON)}"#))
            case 3: return (404, json(#"{"message":"Not found."}"#))
            case 4: return (500, Data())
            default: return (200, json(#"{"status":"linked"}"#))
            }
        }
        let start = try await api.startPairing(device: device)
        #expect(start.code == "K7M4QX")
        #expect(start.secret == "s3cr3t")
        #expect(try await api.pollPairing(secret: "s3cr3t") == .pending)
        let linked = try await api.pollPairing(secret: "s3cr3t")
        #expect(linked == .linked(AuthTokenResponse(token: "tok-new", user: AuthUser(id: 1, name: "Reyhan", username: "reyhan", role: .admin))))
        #expect(try await api.pollPairing(secret: "s3cr3t") == .expired)
        await #expect(throws: APIError.http(status: 500, message: nil)) { _ = try await api.pollPairing(secret: "s3cr3t") }
        // "linked" without a token is not a sign-in.
        await #expect(throws: (any Error).self) { _ = try await api.pollPairing(secret: "s3cr3t") }

        let requests = log.requests
        #expect(requests[0].request.httpMethod == "POST")
        #expect(requests[0].request.url?.path == "/api/v1/auth/pairings")
        #expect(try bodyJSON(requests[0].body) == ["device_id": "i_testdevice01", "device_name": "iPhone"])
        #expect(requests[1].request.httpMethod == "GET")
        #expect(requests[1].request.url?.path == "/api/v1/auth/pairings/s3cr3t")
        #expect(requests.allSatisfy { $0.request.value(forHTTPHeaderField: "Authorization") == nil })
    }
}

// MARK: - Second-step rules

@Suite("Second step: rules and wording")
struct SecondStepFlowTests {
    @Test func defaultMethod() {
        #expect(SecondStepFlow.defaultMethod([.email, .recovery, .totp]) == .totp)
        #expect(SecondStepFlow.defaultMethod([.recovery, .email]) == .email)
        #expect(SecondStepFlow.defaultMethod([.recovery]) == .recovery)
    }

    @Test func choosingAMethod() {
        var flow = SecondStepFlow(server: server, username: "reyhan", challenge: challenge([.totp, .recovery]))
        #expect(flow.method == .totp)
        #expect(flow.otherMethods == [.recovery])
        flow.select(.email) // not offered
        #expect(flow.method == .totp)
        flow.select(.recovery)
        #expect(flow.method == .recovery)
        #expect(flow.otherMethods == [.totp])
        #expect(SecondStepMethod.allCases.map(\.label) == ["Authenticator app", "Email me a code", "Use a recovery code"])
    }

    @Test func texts() {
        let flow = SecondStepFlow(server: server, username: "r", challenge: challenge([.email], hint: "r•••@a•••.com.au"))
        #expect(flow.title == "Enter your sign-in code")
        #expect(flow.prompt == "Enter your sign-in code to finish signing in.")
        #expect(flow.emailSentText == "We sent a code to r•••@a•••.com.au.")
        let noHint = SecondStepFlow(server: server, username: "r", challenge: challenge([.email]))
        #expect(noHint.emailSentText == "We sent a code to your email address.")
    }

    @Test func sendAgainAfterThirtySeconds() {
        let start = Date(timeIntervalSince1970: 1_800_000_000)
        var flow = SecondStepFlow(server: server, username: "r", challenge: challenge([.email]))
        #expect(flow.canSendEmail(at: start))
        #expect(!flow.emailSent)
        flow.emailSent(at: start)
        #expect(flow.emailSent)
        #expect(!flow.canSendEmail(at: start.addingTimeInterval(29.9)))
        #expect(flow.secondsUntilResend(at: start.addingTimeInterval(10)) == 20)
        #expect(flow.canSendEmail(at: start.addingTimeInterval(30)))
        #expect(flow.secondsUntilResend(at: start.addingTimeInterval(31)) == 0)
    }

    @Test func codeFormats() {
        #expect(SecondStepFlow.normalize("12 34-56 7", for: .totp) == "123456")
        #expect(SecondStepFlow.normalize("１23", for: .email) == "23") // ASCII digits only
        #expect(SecondStepFlow.normalize("ABCDE fghij", for: .recovery) == "abcde-fghij")
        #expect(SecondStepFlow.normalize("abcdefghijXYZ", for: .recovery) == "abcde-fghij")
        #expect(SecondStepFlow.normalize("abc", for: .recovery) == "abc")
        #expect(SecondStepFlow.normalize("abcde-", for: .recovery) == "abcde")
        #expect(SecondStepFlow.isComplete("123456", for: .totp))
        #expect(!SecondStepFlow.isComplete("12345", for: .email))
        #expect(SecondStepFlow.isComplete("abcde-fghij", for: .recovery))
        #expect(SecondStepFlow.isComplete("abcdefghij", for: .recovery))
        #expect(!SecondStepFlow.isComplete("abcde-fghi", for: .recovery))
    }

    @Test func answers() {
        var flow = SecondStepFlow(server: server, username: "r", challenge: challenge([.totp]))
        let wrong = "That code is not right. Check it and try again."
        let r1 = flow.receive(.wrongCode(message: wrong, triesLeft: 3))
        #expect(r1 == .retry)
        #expect(flow.errorMessage == wrong + " 3 tries left.")
        _ = flow.receive(.wrongCode(message: wrong, triesLeft: 1))
        #expect(flow.errorMessage == wrong + " 1 try left.")
        _ = flow.receive(.wrongCode(message: nil, triesLeft: nil))
        #expect(flow.errorMessage == wrong)
        _ = flow.receive(.unreachable("offline"))
        #expect(flow.errorMessage == "No connection. Try again.")
        _ = flow.receive(.timedOut)
        #expect(flow.errorMessage == "No connection. Try again.")
        _ = flow.receive(.http(status: 429, message: "Too many attempts. Try again in 60 seconds."))
        #expect(flow.errorMessage == "Too many attempts. Try again in 60 seconds.")
        _ = flow.receive(.http(status: 429, message: nil))
        #expect(flow.errorMessage == "Too many sign-in attempts. Wait a minute and try again.")
        let r2 = flow.receive(.challengeExpired(nil)) == .restart("That took too long. Sign in again.")
        #expect(r2)
        // Choosing another method clears the last error.
        var two = SecondStepFlow(server: server, username: "r", challenge: challenge([.totp, .recovery]))
        _ = two.receive(.timedOut)
        two.select(.recovery)
        #expect(two.errorMessage == nil)
    }

    @Test func passwordFormWording() {
        #expect(SignInMessages.message(for: .secondStepUnavailable(unavailableMessage)) == unavailableMessage)
        #expect(SignInMessages.message(for: .secondStepUnavailable(nil)) == unavailableMessage)
        #expect(SignInMessages.message(for: .challengeExpired(nil)) == "That took too long. Sign in again.")
        #expect(SignInMessages.message(for: .challengeExpired("Too many wrong codes. Sign in again."))
                == "Too many wrong codes. Sign in again.")
        // A passkey-only account: the server's text, word for word.
        #expect(SignInMessages.message(for: .secondStepUnavailable(passkeyOnlyMessage)) == passkeyOnlyMessage)
        #expect(APIError.secondStepUnavailable(nil).isRetryable == false)
    }
}

// MARK: - Pairing rules

@Suite("Link with a code: rules and wording")
struct PairingFlowTests {
    let t0 = Date(timeIntervalSince1970: 1_800_000_000)

    @Test func codeAndInterval() {
        #expect(PairingFlow.formatCode("k7m4qx") == "K7M 4QX")
        #expect(PairingFlow.formatCode("K7M 4QX") == "K7M 4QX")
        #expect(PairingFlow.formatCode("ABCD") == "ABCD")
        #expect(PairingFlow.clampInterval(nil) == 3)
        #expect(PairingFlow.clampInterval(0) == 2)
        #expect(PairingFlow.clampInterval(5) == 5)
        #expect(PairingFlow.clampInterval(120) == 30)
    }

    @Test func pollsUntilLinked() {
        var flow = PairingFlow(server: server)
        #expect(flow.isActive)
        let r3 = flow.started(PairingStart(code: "K7M4QX", secret: "s", expiresIn: 600, interval: 3), at: t0) == .poll(after: 3)
        #expect(r3)
        #expect(flow.phase == .waiting)
        #expect(flow.code == "K7M 4QX")
        let r4 = flow.received(.pending, at: t0.addingTimeInterval(3)) == .poll(after: 3)
        #expect(r4)
        // No answer this time: keep going.
        let r5 = flow.pollFailed(.unreachable("offline"), at: t0.addingTimeInterval(6)) == .poll(after: 3)
        #expect(r5)
        let r6 = flow.pollFailed(.http(status: 503, message: nil), at: t0.addingTimeInterval(9)) == .poll(after: 3)
        #expect(r6)
        let r7 = flow.pollFailed(.http(status: 429, message: nil), at: t0.addingTimeInterval(12)) == .poll(after: 3)
        #expect(r7)
        let user = AuthUser(id: 1, name: "R", username: "r", role: .reader)
        let r8 = flow.received(.linked(AuthTokenResponse(token: "t", user: user)), at: t0.addingTimeInterval(15))
        #expect(r8 == .finished)
        #expect(flow.phase == .linked)
        #expect(!flow.isActive)
        // Nothing moves once finished.
        let r9 = flow.received(.pending, at: t0)
        #expect(r9 == .finished)
    }

    @Test func expiry() {
        var flow = PairingFlow(server: server)
        _ = flow.started(PairingStart(code: "K7M4QX", secret: "s", expiresIn: 10, interval: 4), at: t0)
        // The last wait is cut short so the expiry shows on time.
        let r10 = flow.received(.pending, at: t0.addingTimeInterval(8)) == .poll(after: 2)
        #expect(r10)
        let r11 = flow.expireIfDue(at: t0.addingTimeInterval(9))
        #expect(r11 == false)
        let r12 = flow.expireIfDue(at: t0.addingTimeInterval(10))
        #expect(r12)
        #expect(flow.phase == .expired)

        var gone = PairingFlow(server: server)
        _ = gone.started(PairingStart(code: "K7M4QX", secret: "s"), at: t0)
        #expect(gone.expiresAt == t0.addingTimeInterval(600)) // default lifetime
        #expect(gone.interval == 3)                           // default interval
        let r13 = gone.received(.expired, at: t0)
        #expect(r13 == .finished)
        #expect(gone.phase == .expired)

        var late = PairingFlow(server: server)
        _ = late.started(PairingStart(code: "K7M4QX", secret: "s", expiresIn: 5, interval: 3), at: t0)
        let r14 = late.received(.pending, at: t0.addingTimeInterval(6))
        #expect(r14 == .finished)
        #expect(late.phase == .expired)
    }

    @Test func failures() {
        var flow = PairingFlow(server: server)
        _ = flow.started(PairingStart(code: "K7M4QX", secret: "s", expiresIn: 600, interval: 3), at: t0)
        let r15 = flow.pollFailed(.decoding("bad"), at: t0)
        #expect(r15 == .finished)
        #expect(flow.phase == .failed("The server's answer wasn't in the expected format."))

        var start = PairingFlow(server: server)
        let r16 = start.startFailed(.unreachable("x"))
        #expect(r16 == .finished)
        #expect(start.phase == .failed("No connection. Try again."))
        #expect(PairingFlow.startMessage(for: .http(status: 429, message: nil))
                == "Too many codes were asked for. Wait a while and try again.")
        #expect(PairingFlow.startMessage(for: .http(status: 429, message: "Slow down.")) == "Slow down.")
    }

    @Test func screenTexts() {
        var flow = PairingFlow(server: URL(string: "http://192.168.1.5:8080")!)
        #expect(flow.instructions == "On a phone or computer where you are signed in, open 192.168.1.5:8080 → Account → Link a device and enter this code.")
        #expect(PairingFlow.serverName(server) == "novarr.example.com")
        #expect(flow.validUntilText() == nil)
        _ = flow.started(PairingStart(code: "K7M4QX", secret: "s", expiresIn: 600, interval: 3), at: t0)
        let text = flow.validUntilText(timeZone: TimeZone(identifier: "Australia/Perth")!, locale: Locale(identifier: "en_GB"))
        // 1_800_000_000 + 600 s is 2027-01-15 08:10 UTC, 16:10 in Perth.
        #expect(text == "This code works until 16:10.")
    }
}

// MARK: - The app model

@Suite("Second step and link code in the app")
@MainActor
struct SecondStepAppTests {
    private func makeApp(_ handler: @escaping StubURLProtocol.Handler) -> (AppModel, URL, CredentialStore) {
        let (base, session) = StubURLProtocol.register(handler)
        let credentials = CredentialStore(secure: InMemorySecureStore())
        let app = AppModel(defaults: signInDefaults(server: base), credentials: credentials,
                           session: session, storageBase: scratchDirectory(), refreshMetaOnLaunch: false)
        return (app, base, credentials)
    }

    @Test func passwordThenCodeSignsIn() async throws {
        let log = RequestLog()
        let (app, base, credentials) = makeApp { request, body in
            log.append(request, body)
            switch request.url?.path {
            case "/api/v1/auth/token": return (401, challengeBody)
            case "/api/v1/auth/token/second-step": return (200, tokenBody)
            default: return (204, Data())
            }
        }
        #expect(app.needsSignIn)
        try await app.signIn(username: "reyhan", password: "pw")
        let flow = try #require(app.secondStep)
        #expect(flow.method == .totp)
        #expect(app.needsSignIn)
        #expect(credentials.credential(for: base) == nil)

        await app.submitSecondStep(code: "123 456")
        #expect(app.secondStep == nil)
        #expect(app.needsSignIn == false)
        #expect(app.account?.token == "tok-new")
        #expect(credentials.credential(for: base)?.token == "tok-new")
        #expect(app.lastUsername == "reyhan")

        let step = try #require(log.requests.first { $0.request.url?.path == "/api/v1/auth/token/second-step" })
        let body = try bodyJSON(step.body)
        let password = try #require(log.requests.first { $0.request.url?.path == "/api/v1/auth/token" })
        let deviceId = try bodyJSON(password.body)["device_id"]
        #expect(deviceId == app.device.id)
        // The same device id as the password call: the challenge is bound to it.
        #expect(body == ["challenge": challengeHex, "method": "totp", "code": "123456", "device_id": app.device.id])
        #expect(body["password"] == nil) // the password went out once
    }

    @Test func wrongCodeThenExpiredGoesBackToThePassword() async throws {
        let answers = Counter()
        let (app, _, _) = makeApp { request, _ in
            if request.url?.path == "/api/v1/auth/token" { return (401, challengeBody) }
            return answers.next("step") == 1 ? (422, wrongCodeBody) : (404, expiredBody)
        }
        try await app.signIn(username: "reyhan", password: "pw")
        await app.submitSecondStep(code: "000000")
        #expect(app.secondStep?.errorMessage == "That code is not right. Check it and try again. 3 tries left.")
        await app.submitSecondStep(code: "111111")
        #expect(app.secondStep == nil)
        #expect(app.signInError == "That took too long. Sign in again.")
        #expect(app.needsSignIn)
        #expect(app.lastUsername == "reyhan") // the name is kept for the form, the password isn't
    }

    @Test func offlineCodeSaysNoConnection() async throws {
        let (app, _, _) = makeApp { request, _ in
            if request.url?.path == "/api/v1/auth/token" { return (401, challengeBody) }
            throw URLError(.notConnectedToInternet)
        }
        try await app.signIn(username: "reyhan", password: "pw")
        await app.submitSecondStep(code: "123456")
        #expect(app.secondStep?.errorMessage == "No connection. Try again.")
    }

    @Test func emailedCodeIsSentOnlyWhenAskedAndNotAgainWithin30Seconds() async throws {
        let counts = Counter()
        let log = RequestLog()
        let (app, _, _) = makeApp { request, body in
            if request.url?.path == "/api/v1/auth/token" {
                return (401, json(#"{"code":"second_step_required","challenge":"c1","expires_in":600,"methods":["email","recovery"],"email_hint":"r•••@a•••.com.au"}"#))
            }
            _ = counts.next(request.url?.path ?? "")
            log.append(request, body)
            return (204, Data())
        }
        var clock = Date(timeIntervalSince1970: 1_800_000_000)
        let now = { @Sendable [clock] in clock }
        app.currentDate = now
        try await app.signIn(username: "reyhan", password: "pw")
        #expect(app.secondStep?.method == .email)
        #expect(counts.count("/api/v1/auth/token/email-code") == 0) // never auto-sent
        await app.requestEmailCode()
        #expect(app.secondStep?.emailSent == true)
        await app.requestEmailCode() // within 30 s: not sent
        #expect(counts.count("/api/v1/auth/token/email-code") == 1)
        clock = clock.addingTimeInterval(30)
        app.currentDate = { @Sendable [clock] in clock }
        await app.requestEmailCode()
        #expect(counts.count("/api/v1/auth/token/email-code") == 2)
        for sent in log.requests {
            #expect(try bodyJSON(sent.body) == ["challenge": "c1", "device_id": app.device.id])
        }
    }

    @Test func fifthWrongCodeShowsTheServersReason() async throws {
        let answers = Counter()
        let (app, _, _) = makeApp { request, _ in
            if request.url?.path == "/api/v1/auth/token" { return (401, challengeBody) }
            return answers.next("step") == 1 ? (429, throttledBody) : (404, tooManyWrongBody)
        }
        try await app.signIn(username: "reyhan", password: "pw")
        await app.submitSecondStep(code: "000000")
        // 429 with retry_after: the message as given, still on the code screen.
        #expect(app.secondStep?.errorMessage == "Too many attempts. Try again in 60 seconds.")
        await app.submitSecondStep(code: "000000")
        #expect(app.secondStep == nil)
        #expect(app.signInError == "Too many wrong codes. Sign in again.")
    }

    @Test func passkeyOnlyAccountGetsTheServersMessageVerbatim() async throws {
        let (app, _, _) = makeApp { _, _ in
            (403, json(#"{"code":"second_step_unavailable","message":"\#(passkeyOnlyMessage)"}"#))
        }
        do {
            try await app.signIn(username: "reyhan", password: "pw")
            Issue.record("expected a refusal")
        } catch {
            let apiError = APIError.from(error)
            #expect(apiError == .secondStepUnavailable(passkeyOnlyMessage))
            #expect(SignInMessages.message(for: apiError) == passkeyOnlyMessage)
        }
        #expect(app.secondStep == nil)
    }

    @Test func secondStepUnavailableIsThrownForTheForm() async throws {
        let (app, _, _) = makeApp { _, _ in
            (403, json(#"{"code":"second_step_unavailable","message":"\#(unavailableMessage)"}"#))
        }
        await #expect(throws: APIError.secondStepUnavailable(unavailableMessage)) {
            try await app.signIn(username: "reyhan", password: "pw")
        }
        #expect(app.secondStep == nil)
        #expect(app.needsSignIn)
    }

    @Test func linkCodeSignsInAndAsksForOneCodeOnly() async throws {
        let counts = Counter()
        let delays = Delays()
        let (app, base, credentials) = makeApp { request, _ in
            if request.httpMethod == "POST" {
                _ = counts.next("start")
                return (201, json(#"{"code":"K7M4QX","secret":"s3cr3t","expires_in":600,"interval":1}"#))
            }
            switch counts.next("poll") {
            case 1: return (200, json(#"{"status":"pending"}"#))
            case 2: return (500, Data())
            case 3: throw URLError(.notConnectedToInternet)
            case 4: return (429, throttledBody)
            default: return (200, json(#"{"status":"linked","token":"tok-link","user":\#(userJSON)}"#))
            }
        }
        app.sleep = { seconds in
            delays.append(seconds)
            await Task.yield()
        }
        app.startPairing()
        app.startPairing() // the screen appearing again
        try await waitUntil("signed in") { app.account != nil }
        #expect(counts.count("start") == 1)
        #expect(counts.count("poll") == 5)
        #expect(delays.values.allSatisfy { $0 == 2 }) // 1 s from the server, clamped to 2
        #expect(app.pairing == nil)
        #expect(app.needsSignIn == false)
        #expect(credentials.credential(for: base)?.token == "tok-link")
    }

    @Test func expiredCodeOffersANewOne() async throws {
        let counts = Counter()
        let (app, _, _) = makeApp { request, _ in
            if request.httpMethod == "POST" {
                let n = counts.next("start")
                return (201, json(#"{"code":"AAA22\#(n)","secret":"s\#(n)","expires_in":600,"interval":3}"#))
            }
            return (404, Data())
        }
        app.sleep = { _ in await Task.yield() }
        app.startPairing()
        try await waitUntil("expired") { app.pairing?.phase == .expired }
        app.startPairing() // nothing active: a new code is fine
        try await waitUntil("second code") { counts.count("start") == 2 }
        app.cancelPairing()
        #expect(app.pairing == nil)
    }

    @Test func cancelStopsPolling() async throws {
        let counts = Counter()
        let (app, _, _) = makeApp { request, _ in
            if request.httpMethod == "POST" {
                return (201, json(#"{"code":"K7M4QX","secret":"s","expires_in":600,"interval":2}"#))
            }
            _ = counts.next("poll")
            return (200, json(#"{"status":"pending"}"#))
        }
        app.sleep = { _ in try await Task.sleep(for: .milliseconds(20)) }
        app.startPairing()
        try await waitUntil("polling") { counts.count("poll") >= 2 }
        app.cancelPairing()
        let polls = counts.count("poll")
        try await Task.sleep(for: .milliseconds(150))
        #expect(counts.count("poll") <= polls + 1) // at most the one already in flight
        #expect(app.pairing == nil)
        #expect(app.needsSignIn)
    }

    @Test func secondStepOverridesALinkCodeInProgress() async throws {
        let (app, _, _) = makeApp { request, _ in
            if request.url?.path == "/api/v1/auth/token" { return (401, challengeBody) }
            if request.httpMethod == "POST" {
                return (201, json(#"{"code":"K7M4QX","secret":"s","expires_in":600,"interval":2}"#))
            }
            return (200, json(#"{"status":"pending"}"#))
        }
        app.sleep = { _ in try await Task.sleep(for: .milliseconds(20)) }
        app.startPairing()
        try await waitUntil("code shown") { app.pairing?.phase == .waiting }
        try await app.signIn(username: "reyhan", password: "pw")
        #expect(app.pairing == nil)
        #expect(app.secondStep != nil)
    }
}

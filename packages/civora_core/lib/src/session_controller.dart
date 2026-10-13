enum SessionState { unauthenticated, activationRequired, authenticated }

class SessionController {
  SessionState state = SessionState.unauthenticated;
  String? bearerToken;
  String? role;

  void authenticate(String token, {required String role, bool activationRequired = false}) {
    bearerToken = token;
    this.role = role;
    state = activationRequired ? SessionState.activationRequired : SessionState.authenticated;
  }

  void end() {
    bearerToken = null;
    role = null;
    state = SessionState.unauthenticated;
  }
}

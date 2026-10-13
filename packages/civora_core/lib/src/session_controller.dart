import 'package:flutter/foundation.dart';

import 'api_client.dart';
import 'api_exception.dart';
import 'dto.dart';
import 'secure_token_store.dart';

enum SessionState { unauthenticated, activationRequired, authenticated }

class SessionRoleException implements Exception {
  const SessionRoleException();
}

class SessionController extends ChangeNotifier {
  SessionController({required this.role, required SessionTokenStore tokenStore})
    : _tokenStore = tokenStore;

  final String role;
  final SessionTokenStore _tokenStore;
  ApiClient? _api;
  SessionState state = SessionState.unauthenticated;
  String? bearerToken;
  MeDto? profile;
  Object? restoreError;
  String? noticeMessage;
  bool isBusy = false;
  bool isRestoring = false;

  String get _storageKey => 'civora.$role.token';

  void bindApi(ApiClient api) => _api = api;

  Future<void> restore() async {
    isRestoring = true;
    restoreError = null;
    noticeMessage = null;
    notifyListeners();
    try {
      final token = await _tokenStore.read(_storageKey);
      if (token == null || token.isEmpty) return;
      await _loadProfile(token);
    } on SessionRoleException {
      await _clearLocalSession();
    } on ApiException catch (error) {
      if (error.statusCode == 401 || error.statusCode == 403) {
        await _clearLocalSession();
      } else {
        restoreError = error;
        end();
      }
    } catch (error) {
      restoreError = error;
      end();
    } finally {
      isRestoring = false;
      notifyListeners();
    }
  }

  Future<void> login(
    String identifier,
    String password, {
    required String role,
  }) async {
    if (role != this.role) throw const SessionRoleException();
    final api = _requiredApi;
    isBusy = true;
    restoreError = null;
    notifyListeners();
    try {
      final path = role == 'student'
          ? '/auth/student/login'
          : '/auth/teacher/login';
      final body = <String, dynamic>{
        (role == 'student' ? 'nisn' : 'identifier'): identifier,
        'password': password,
      };
      final response = await api.post(path, body);
      final accountRole = response['role'];
      final token = response['token'];
      if (accountRole != role || token is! String || token.isEmpty) {
        throw const SessionRoleException();
      }
      bearerToken = token;
      await _loadProfile(token);
      await _tokenStore.write(_storageKey, token);
    } catch (_) {
      await _clearLocalSession();
      rethrow;
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }

  Future<void> changePassword(
    String currentPassword,
    String newPassword,
  ) async {
    final length = newPassword.runes.length;
    if (length < 12 || length > 1024) {
      throw const PasswordLengthException();
    }
    if (newPassword == currentPassword) {
      throw const PasswordMustDifferException();
    }
    isBusy = true;
    notifyListeners();
    try {
      await _requiredApi.post('/auth/change-password', {
        'current_password': currentPassword,
        'password': newPassword,
        'password_confirmation': newPassword,
      });
      await _clearLocalSession();
      noticeMessage =
          'Password berhasil diganti. Masuk kembali menggunakan password baru.';
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    final token = bearerToken;
    isBusy = true;
    notifyListeners();
    try {
      if (token != null) await _requiredApi.post('/auth/logout', const {});
    } finally {
      await _clearLocalSession();
      isBusy = false;
      notifyListeners();
    }
  }

  Future<void> _loadProfile(String token) async {
    bearerToken = token;
    final data = await _requiredApi.get('/me');
    final loaded = MeDto.fromJson(data);
    if (loaded.account.role != role) throw const SessionRoleException();
    profile = loaded;
    state =
        loaded.account.mustChangePassword ||
            loaded.account.accountStatus == 'pending_activation'
        ? SessionState.activationRequired
        : SessionState.authenticated;
    notifyListeners();
  }

  Future<void> _clearLocalSession() async {
    end();
    await _tokenStore.delete(_storageKey);
  }

  void end() {
    bearerToken = null;
    profile = null;
    state = SessionState.unauthenticated;
    notifyListeners();
  }

  Future<void> expire() => _clearLocalSession();

  ApiClient get _requiredApi =>
      _api ?? (throw StateError('SessionController has no ApiClient.'));
}

class PasswordLengthException implements Exception {
  const PasswordLengthException();
}

class PasswordMustDifferException implements Exception {
  const PasswordMustDifferException();
}

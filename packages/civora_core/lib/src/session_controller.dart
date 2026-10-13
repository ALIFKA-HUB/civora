import 'dart:async';

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
  int _generation = 0;
  Future<void> _storageQueue = Future<void>.value();
  Future<void> Function()? beforeLogout;

  int get generation => _generation;

  String get _storageKey => 'civora.$role.token';

  void bindApi(ApiClient api) => _api = api;

  Future<void> restore() async {
    final generation = ++_generation;
    isRestoring = true;
    restoreError = null;
    noticeMessage = null;
    notifyListeners();
    try {
      final token = await _queueStorage(() => _tokenStore.read(_storageKey));
      if (generation != _generation) return;
      if (token == null || token.isEmpty) return;
      await _loadProfile(token, generation);
    } on SessionRoleException {
      await _clearLocalSession(generation);
    } on ApiException catch (error) {
      if (generation != _generation) return;
      if (error.statusCode == 401 || error.statusCode == 403) {
        await _clearLocalSession(generation);
      } else {
        restoreError = error;
        end();
      }
    } catch (error) {
      if (generation != _generation) return;
      restoreError = error;
      end();
    } finally {
      if (generation == _generation) {
        isRestoring = false;
        notifyListeners();
      }
    }
  }

  Future<void> login(
    String identifier,
    String password, {
    required String role,
  }) async {
    if (role != this.role) throw const SessionRoleException();
    final api = _requiredApi;
    final generation = ++_generation;
    isBusy = true;
    isRestoring = false;
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
      if (generation != _generation) return;
      final accountRole = response['role'];
      final token = response['token'];
      if (accountRole != role || token is! String || token.isEmpty) {
        throw const SessionRoleException();
      }
      bearerToken = token;
      await _loadProfile(token, generation);
      if (generation == _generation) {
        await _queueStorage(() async {
          if (generation == _generation)
            await _tokenStore.write(_storageKey, token);
        });
      }
    } catch (_) {
      if (generation == _generation) await _clearLocalSession(generation);
      rethrow;
    } finally {
      if (generation == _generation) {
        isBusy = false;
        notifyListeners();
      }
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
    final generation = ++_generation;
    isBusy = true;
    isRestoring = false;
    notifyListeners();
    try {
      await _requiredApi.post('/auth/change-password', {
        'current_password': currentPassword,
        'password': newPassword,
        'password_confirmation': newPassword,
      });
      if (generation != _generation) return;
      await _clearLocalSession(generation);
      noticeMessage =
          'Password berhasil diganti. Masuk kembali menggunakan password baru.';
    } finally {
      if (generation == _generation) {
        isBusy = false;
        notifyListeners();
      }
    }
  }

  Future<void> logout() async {
    final token = bearerToken;
    final generation = ++_generation;
    isBusy = true;
    isRestoring = false;
    notifyListeners();
    try {
      await beforeLogout?.call();
      if (token != null) await _requiredApi.post('/auth/logout', const {});
    } finally {
      if (generation == _generation) await _clearLocalSession(generation);
      if (generation == _generation) {
        isBusy = false;
        notifyListeners();
      }
    }
  }

  Future<void> _loadProfile(String token, int generation) async {
    if (generation != _generation) return;
    bearerToken = token;
    final data = await _requiredApi.get('/me');
    if (generation != _generation) return;
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

  Future<void> _clearLocalSession(int generation) async {
    if (generation != _generation) return;
    end();
    await _queueStorage(() => _tokenStore.delete(_storageKey));
  }

  void end() {
    _generation++;
    bearerToken = null;
    profile = null;
    state = SessionState.unauthenticated;
    isBusy = false;
    isRestoring = false;
    notifyListeners();
  }

  Future<void> expireIfCurrent(String? requestToken, int requestGeneration) {
    if (requestToken == null ||
        requestToken != bearerToken ||
        requestGeneration != _generation) {
      return Future<void>.value();
    }
    return _clearLocalSession(requestGeneration);
  }

  Future<T> _queueStorage<T>(Future<T> Function() action) {
    final next = _storageQueue.then((_) => action());
    _storageQueue = next.then<void>(
      (_) {},
      onError: (Object _, StackTrace __) {},
    );
    return next;
  }

  ApiClient get _requiredApi =>
      _api ?? (throw StateError('SessionController has no ApiClient.'));
}

class PasswordLengthException implements Exception {
  const PasswordLengthException();
}

class PasswordMustDifferException implements Exception {
  const PasswordMustDifferException();
}

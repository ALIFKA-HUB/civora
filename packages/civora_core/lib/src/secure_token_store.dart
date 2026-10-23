import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract interface class SessionTokenStore {
  Future<String?> read(String key);
  Future<void> write(String key, String token);
  Future<void> delete(String key);
}

class FlutterSecureSessionTokenStore implements SessionTokenStore {
  FlutterSecureSessionTokenStore({
    required String role,
    FlutterSecureStorage? storage,
  }) : _key = 'civora.$role.token',
       _storage = storage ?? const FlutterSecureStorage();

  final String _key;
  final FlutterSecureStorage _storage;

  void _checkKey(String key) {
    if (key != _key)
      throw ArgumentError('Token storage key does not match this app role.');
  }

  @override
  Future<String?> read(String key) {
    _checkKey(key);
    return _storage.read(key: _key);
  }

  @override
  Future<void> write(String key, String token) {
    _checkKey(key);
    return _storage.write(key: _key, value: token);
  }

  @override
  Future<void> delete(String key) {
    _checkKey(key);
    return _storage.delete(key: _key);
  }
}

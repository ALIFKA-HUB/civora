import 'dart:async';

import 'package:flutter/foundation.dart';
import 'api_client.dart';
import 'api_exception.dart';
import 'dto.dart';
import 'session_controller.dart';

abstract interface class NotificationPushClient {
  Future<void> initialize();
  Future<bool> requestPermission();
  Future<String?> getToken();
  Stream<String> get tokenRefreshes;
  Stream<Map<String, String>> get openedMessages;
  Stream<Map<String, String>> get foregroundMessages;
}

class NotificationLoginRequired implements Exception {}

class NotificationResourceUnavailable implements Exception {}

class NotificationController extends ChangeNotifier {
  NotificationController({
    required this.api,
    required this.session,
    this.push,
  }) {
    session.addListener(_sessionChanged);
  }

  final ApiClient api;
  final SessionController session;
  final NotificationPushClient? push;
  List<NotificationDto> notifications = const [];
  String? errorMessage;
  bool isLoading = false;
  String? deviceTokenId;
  bool pushAvailable = false;
  bool loginRequested = false;
  Map<String, String>? pendingPush;
  Future<void> Function(String type, String resourceId)? onPushedResource;
  StreamSubscription<String>? _tokenSubscription;
  StreamSubscription<Map<String, String>>? _openedSubscription;
  StreamSubscription<Map<String, String>>? _foregroundSubscription;
  int _registeredGeneration = -1;

  Future<void> load() async {
    final token = session.bearerToken;
    final generation = session.generation;
    errorMessage = null;
    isLoading = true;
    notifyListeners();
    try {
      final result = await api.get('/notifications');
      if (!_isCurrentSession(token, generation)) return;
      final rows = result['data'] is List ? result['data'] as List : const [];
      notifications = rows
          .whereType<Map<String, dynamic>>()
          .map(NotificationDto.fromJson)
          .toList(growable: false);
    } on ApiException catch (error) {
      if (_isCurrentSession(token, generation)) errorMessage = error.message;
      rethrow;
    } finally {
      if (_isCurrentSession(token, generation)) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  bool _isCurrentSession(String? token, int generation) =>
      session.bearerToken == token && session.generation == generation;

  Future<void> markRead(NotificationDto item) async {
    if (item.readAt != null) return;
    final response = await api.post('/notifications/${item.id}/read', const {});
    final timestamp = response['read_at'];
    if (timestamp is! String) return;
    notifications = notifications
        .map(
          (row) => row.id == item.id
              ? NotificationDto(
                  id: row.id,
                  type: row.type,
                  message: row.message,
                  createdAt: row.createdAt,
                  resourceId: row.resourceId,
                  readAt: DateTime.parse(timestamp).toUtc(),
                )
              : row,
        )
        .toList(growable: false);
    notifyListeners();
  }

  Future<void> initializePush() async {
    if (push == null) return;
    try {
      _openedSubscription ??= push!.openedMessages.listen((message) {
        final type = message['type'];
        final id = message['resource_id'];
        if (type == null || id == null) return;
        pendingPush = {'type': type, 'resource_id': id};
        unawaited(_openPendingPush());
      });
      await push!.initialize();
      pushAvailable = true;
      _foregroundSubscription ??= push!.foregroundMessages.listen((_) {
        if (session.state == SessionState.authenticated) unawaited(load());
      });
      _tokenSubscription ??= push!.tokenRefreshes.listen((_) {
        if (session.state == SessionState.authenticated)
          unawaited(registerDevice());
      });
      if (session.state == SessionState.authenticated) await registerDevice();
    } catch (_) {
      pushAvailable = false;
    }
    notifyListeners();
  }

  Future<void> registerDevice() async {
    final client = push;
    if (client == null ||
        !pushAvailable ||
        session.state != SessionState.authenticated)
      return;
    try {
      if (!await client.requestPermission()) return;
      final token = await client.getToken();
      if (token == null ||
          token.isEmpty ||
          session.state != SessionState.authenticated)
        return;
      final generation = session.generation;
      final result = await api.post('/device-tokens', {
        'app': session.role,
        'token': token,
        'platform': 'android',
      });
      if (generation != session.generation) return;
      deviceTokenId = result['id'] as String?;
      _registeredGeneration = generation;
    } catch (_) {
      // Push registration is optional; in-app notifications remain available.
    }
  }

  Future<void> unregisterDevice() async {
    final id = deviceTokenId;
    deviceTokenId = null;
    _registeredGeneration = -1;
    if (id == null || session.state != SessionState.authenticated) return;
    try {
      await api.delete('/device-tokens/$id');
    } catch (_) {
      // The authenticated logout endpoint also revokes this session's binding.
    }
  }

  Future<Map<String, dynamic>> openResource(NotificationDto notification) =>
      openPushedResource(notification.type, notification.resourceId ?? '');

  Future<Map<String, dynamic>> openPushedResource(
    String type,
    String resourceId,
  ) async {
    if (session.state != SessionState.authenticated) {
      loginRequested = true;
      pendingPush = {'type': type, 'resource_id': resourceId};
      notifyListeners();
      throw NotificationLoginRequired();
    }
    final path = _resourcePath(type, resourceId);
    if (path == null) throw NotificationResourceUnavailable();
    final token = session.bearerToken;
    final generation = session.generation;
    final list = await api.get('/notifications');
    if (session.state != SessionState.authenticated ||
        token == null ||
        token != session.bearerToken ||
        generation != session.generation) {
      loginRequested = true;
      throw NotificationLoginRequired();
    }
    final rows = list['data'];
    final owned =
        rows is List &&
        rows.whereType<Map<String, dynamic>>().any(
          (row) => row['type'] == type && row['resource_id'] == resourceId,
        );
    if (!owned || session.state != SessionState.authenticated) {
      throw NotificationResourceUnavailable();
    }
    final resource = await api.get(path);
    if (session.state != SessionState.authenticated ||
        token != session.bearerToken ||
        generation != session.generation) {
      loginRequested = true;
      throw NotificationLoginRequired();
    }
    return resource;
  }

  String? _resourcePath(String type, String id) {
    if (!RegExp(r'^[0-9a-fA-F-]{36}$').hasMatch(id)) return null;
    const studentTypes = {
      'point_record_created',
      'point_record_revised',
      'review_decided',
    };
    const teacherTypes = {
      'point_record_revised',
      'review_submitted',
      'review_decided',
    };
    if (session.role == 'student' && studentTypes.contains(type))
      return '/student/records/$id';
    if (session.role == 'teacher' && teacherTypes.contains(type))
      return '/teacher/records/$id';
    return null;
  }

  Future<void> _openPendingPush() async {
    final pending = pendingPush;
    if (pending == null || session.state != SessionState.authenticated) {
      if (pending != null) loginRequested = true;
      notifyListeners();
      return;
    }
    try {
      await openPushedResource(pending['type']!, pending['resource_id']!);
      pendingPush = null;
      loginRequested = false;
      await onPushedResource?.call(pending['type']!, pending['resource_id']!);
    } on NotificationLoginRequired {
      loginRequested = true;
    } catch (_) {
      pendingPush = null;
    }
    notifyListeners();
  }

  void _sessionChanged() {
    if (session.state == SessionState.authenticated && !session.isBusy) {
      if (pushAvailable && _registeredGeneration != session.generation)
        unawaited(registerDevice());
      unawaited(_openPendingPush());
    } else if (session.state != SessionState.authenticated) {
      deviceTokenId = null;
      notifications = const [];
      errorMessage = null;
      isLoading = false;
    }
    notifyListeners();
  }

  @override
  void dispose() {
    session.removeListener(_sessionChanged);
    unawaited(_tokenSubscription?.cancel());
    unawaited(_openedSubscription?.cancel());
    unawaited(_foregroundSubscription?.cancel());
    super.dispose();
  }
}

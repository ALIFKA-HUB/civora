import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import 'notification_controller.dart';

class FirebaseNotificationPushClient implements NotificationPushClient {
  final _opened = StreamController<Map<String, String>>.broadcast();
  final _foreground = StreamController<Map<String, String>>.broadcast();
  StreamSubscription<RemoteMessage>? _openedSubscription;
  StreamSubscription<RemoteMessage>? _foregroundSubscription;

  @override
  Future<void> initialize() async {
    if (Firebase.apps.isEmpty) await Firebase.initializeApp();
    final messaging = FirebaseMessaging.instance;
    final initial = await messaging.getInitialMessage();
    if (initial != null) _emit(initial);
    _openedSubscription ??= FirebaseMessaging.onMessageOpenedApp.listen(_emit);
    _foregroundSubscription ??= FirebaseMessaging.onMessage.listen(
      _emitForeground,
    );
  }

  void _emit(RemoteMessage message) {
    final type = message.data['type'];
    final id = message.data['resource_id'];
    if (type is String && id is String) {
      _opened.add({'type': type, 'resource_id': id});
    }
  }

  void _emitForeground(RemoteMessage message) {
    final type = message.data['type'];
    final id = message.data['resource_id'];
    if (type is String && id is String)
      _foreground.add({'type': type, 'resource_id': id});
  }

  @override
  Future<bool> requestPermission() async {
    final settings = await FirebaseMessaging.instance.requestPermission();
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<String?> getToken() => FirebaseMessaging.instance.getToken();

  @override
  Stream<String> get tokenRefreshes =>
      FirebaseMessaging.instance.onTokenRefresh;

  @override
  Stream<Map<String, String>> get openedMessages => _opened.stream;

  @override
  Stream<Map<String, String>> get foregroundMessages => _foreground.stream;
}

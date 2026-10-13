import 'dart:convert';
import 'dart:io';

import 'api_exception.dart';
import 'session_controller.dart';

class ApiRequest {
  const ApiRequest({required this.method, required this.uri, required this.headers, this.body});

  final String method;
  final Uri uri;
  final Map<String, String> headers;
  final String? body;
}

class ApiResponse {
  const ApiResponse(this.statusCode, this.body);

  final int statusCode;
  final String body;
}

abstract interface class ApiTransport {
  Future<ApiResponse> send(ApiRequest request);
}

class IoApiTransport implements ApiTransport {
  IoApiTransport([HttpClient? client]) : _client = client ?? HttpClient();

  final HttpClient _client;

  @override
  Future<ApiResponse> send(ApiRequest request) async {
    final outbound = await _client.openUrl(request.method, request.uri);
    request.headers.forEach(outbound.headers.set);
    if (request.body != null) outbound.add(utf8.encode(request.body!));
    final response = await outbound.close();
    return ApiResponse(response.statusCode, await utf8.decoder.bind(response).join());
  }

  void close() => _client.close(force: true);
}

class ApiClient {
  ApiClient({
    required this.baseUrl,
    ApiTransport? transport,
    this.sessionController,
  }) : _transport = transport ?? IoApiTransport();

  final Uri baseUrl;
  final ApiTransport _transport;
  final SessionController? sessionController;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body, {
    String? idempotencyKey,
  }) => _send('POST', path, body: body, idempotencyKey: idempotencyKey);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) async {
    final endpoint = path.startsWith('/') ? path.substring(1) : path;
    final root = baseUrl.path.endsWith('/') ? baseUrl.path : '${baseUrl.path}/';
    final uri = baseUrl.replace(
      path: '$root$endpoint',
      queryParameters: query?.map((key, value) => MapEntry(key, '$value')),
    );
    final headers = <String, String>{'Accept': 'application/json'};
    final isLogin = path == '/auth/student/login' || path == '/auth/teacher/login' ||
        path == 'auth/student/login' || path == 'auth/teacher/login';
    final token = isLogin ? null : sessionController?.bearerToken;
    if (token != null) headers['Authorization'] = 'Bearer $token';
    if (body != null) {
      headers['Content-Type'] = 'application/json; charset=utf-8';
    }
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;

    final response = await _transport.send(ApiRequest(
      method: method,
      uri: uri,
      headers: headers,
      body: body == null ? null : jsonEncode(body),
    ));
    final payload = _decode(response.body);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      if (response.statusCode == 401 && !isLogin) sessionController?.end();
      final error = payload['error'] is Map<String, dynamic>
          ? payload['error'] as Map<String, dynamic>
          : const <String, dynamic>{};
      throw ApiException(
        statusCode: response.statusCode,
        code: error['code'] as String? ?? 'HTTP_ERROR',
        message: error['message'] as String? ?? 'Permintaan gagal. Coba lagi.',
        fields: error['fields'] is Map<String, dynamic>
            ? error['fields'] as Map<String, dynamic>
            : const {},
        requestId: payload['request_id'] as String?,
      );
    }
    final data = payload['data'];
    if (data is Map<String, dynamic>) return data;
    return payload;
  }

  Map<String, dynamic> _decode(String body) {
    if (body.isEmpty) return {};
    try {
      final value = jsonDecode(body);
      return value is Map<String, dynamic> ? value : {};
    } on FormatException {
      return {};
    }
  }
}

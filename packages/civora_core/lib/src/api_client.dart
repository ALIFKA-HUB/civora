import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'api_exception.dart';
import 'session_controller.dart';

class ApiRequest {
  const ApiRequest({
    required this.method,
    required this.uri,
    required this.headers,
    this.body,
  });

  final String method;
  final Uri uri;
  final Map<String, String> headers;
  final String? body;
}

class ApiResponse {
  const ApiResponse(this.statusCode, this.body, {this.bytes});

  final int statusCode;
  final String body;
  final Uint8List? bytes;
}

abstract interface class ApiTransport {
  Future<ApiResponse> send(ApiRequest request);
  void close();
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
    final bytes = await response.fold<List<int>>(
      <int>[],
      (all, chunk) => all..addAll(chunk),
    );
    return ApiResponse(
      response.statusCode,
      utf8.decode(bytes, allowMalformed: true),
      bytes: Uint8List.fromList(bytes),
    );
  }

  @override
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

  void close() => _transport.close();

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) => _send('GET', path, query: query);

  Future<Map<String, dynamic>> post(
    String path,
    Map<String, dynamic> body, {
    String? idempotencyKey,
  }) => _send('POST', path, body: body, idempotencyKey: idempotencyKey);

  Future<Uint8List> getBytes(String path) async {
    final requestToken = sessionController?.bearerToken;
    final requestGeneration = sessionController?.generation ?? -1;
    final response = await _request('GET', path);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      await _throwResponse(response, path, requestToken, requestGeneration);
    }
    return response.bytes ?? Uint8List.fromList(utf8.encode(response.body));
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Map<String, dynamic>? body,
    String? idempotencyKey,
  }) async {
    final requestToken = sessionController?.bearerToken;
    final requestGeneration = sessionController?.generation ?? -1;
    final response = await _request(
      method,
      path,
      query: query,
      body: body,
      idempotencyKey: idempotencyKey,
    );
    final payload = _decode(response.body);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      await _throwResponse(response, path, requestToken, requestGeneration);
    }
    final data = payload['data'];
    if (data is Map<String, dynamic>) return data;
    return payload;
  }

  Future<ApiResponse> _request(
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
    final isLogin =
        path == '/auth/student/login' ||
        path == '/auth/teacher/login' ||
        path == 'auth/student/login' ||
        path == 'auth/teacher/login';
    final token = isLogin ? null : sessionController?.bearerToken;
    if (token != null) headers['Authorization'] = 'Bearer $token';
    if (body != null)
      headers['Content-Type'] = 'application/json; charset=utf-8';
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;
    return _transport.send(
      ApiRequest(
        method: method,
        uri: uri,
        headers: headers,
        body: body == null ? null : jsonEncode(body),
      ),
    );
  }

  Future<void> _throwResponse(
    ApiResponse response,
    String path,
    String? requestToken,
    int requestGeneration,
  ) async {
    final isLogin =
        path == '/auth/student/login' ||
        path == '/auth/teacher/login' ||
        path == 'auth/student/login' ||
        path == 'auth/teacher/login';
    if (response.statusCode == 401 && !isLogin) {
      await sessionController?.expireIfCurrent(requestToken, requestGeneration);
    }
    final payload = _decode(response.body);
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

class ApiException implements Exception {
  const ApiException({
    required this.statusCode,
    required this.code,
    required this.message,
    this.fields = const {},
    this.requestId,
  });

  final int statusCode;
  final String code;
  final String message;
  final Map<String, dynamic> fields;
  final String? requestId;

  @override
  String toString() => 'ApiException($statusCode, $code): $message';
}

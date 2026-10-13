import 'package:flutter/foundation.dart';

const civoraApiBaseUrl = String.fromEnvironment(
  'CIVORA_API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8000/api/v1',
);

@immutable
class CivoraAppConfig {
  const CivoraAppConfig({required this.apiBaseUrl});

  factory CivoraAppConfig.fromEnvironment() =>
      CivoraAppConfig(apiBaseUrl: Uri.parse(civoraApiBaseUrl));

  final Uri apiBaseUrl;
}

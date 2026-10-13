import 'dart:async';
import 'dart:convert';

import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/features/students/student_search_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _SearchTransport implements ApiTransport {
  final requests = <ApiRequest>[];
  final pending = <Completer<ApiResponse>>[];

  @override
  Future<ApiResponse> send(ApiRequest request) {
    requests.add(request);
    final response = Completer<ApiResponse>();
    pending.add(response);
    return response.future;
  }

  @override
  void close() {}
}

ApiResponse _students(String id, String name) => ApiResponse(
  200,
  jsonEncode({
    'data': [
      {
        'id': id,
        'name': name,
        'nisn': id,
        'classroom': {'name': 'VII A'},
        'major': {'name': 'Umum'},
      },
    ],
    'meta': {
      'filters': {'classrooms': [], 'majors': []},
    },
  }),
);

void main() {
  testWidgets('slower earlier result cannot replace newest student search', (
    tester,
  ) async {
    final transport = _SearchTransport();
    final api = ApiClient(
      baseUrl: Uri.parse('http://localhost/api/v1'),
      transport: transport,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: TeacherStudentSearchScreen(api: api, onSelected: (_) {}),
      ),
    );
    expect(transport.requests, hasLength(1));

    await tester.enterText(find.byType(TextField).first, 'Alya');
    await tester.pump(const Duration(milliseconds: 301));
    expect(transport.requests, hasLength(2));
    expect(transport.requests.last.uri.queryParameters['q'], 'Alya');

    transport.pending[1].complete(_students('new-student', 'Alya Putri'));
    await tester.pumpAndSettle();
    expect(find.text('Alya Putri'), findsOneWidget);

    transport.pending[0].complete(_students('old-student', 'Budi Santoso'));
    await tester.pumpAndSettle();
    expect(find.text('Alya Putri'), findsOneWidget);
    expect(find.text('Budi Santoso'), findsNothing);
    api.close();
  });

  testWidgets('stale search failure cannot replace newer results or loading', (
    tester,
  ) async {
    final transport = _SearchTransport();
    final api = ApiClient(
      baseUrl: Uri.parse('http://localhost/api/v1'),
      transport: transport,
    );
    await tester.pumpWidget(
      MaterialApp(
        home: TeacherStudentSearchScreen(api: api, onSelected: (_) {}),
      ),
    );
    await tester.enterText(find.byType(TextField).first, 'Alya');
    await tester.pump(const Duration(milliseconds: 301));
    transport.pending[1].complete(_students('new-student', 'Alya Putri'));
    await tester.pumpAndSettle();

    transport.pending[0].complete(
      ApiResponse(
        500,
        jsonEncode({
          'error': {
            'code': 'OLD_SEARCH_FAILED',
            'message': 'Hasil lama gagal.',
          },
        }),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Alya Putri'), findsOneWidget);
    expect(find.text('Hasil lama gagal.'), findsNothing);
    expect(find.byType(LinearProgressIndicator), findsNothing);
    api.close();
  });
}

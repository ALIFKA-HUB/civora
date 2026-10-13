import 'dart:convert';
import 'package:civora_core/civora_core.dart';
import 'package:civora_teacher/features/reviews/correction_screen.dart';
import 'package:flutter_test/flutter_test.dart';

class _Transport implements ApiTransport {
  final requests = <ApiRequest>[];
  final responses = <ApiResponse>[];
  @override
  Future<ApiResponse> send(ApiRequest request) async {
    requests.add(request);
    return responses.removeAt(0);
  }

  @override
  void close() {}
}

void main() {
  test(
    'correction request stays pending without changing record totals',
    () async {
      final transport = _Transport()
        ..responses.add(
          ApiResponse(
            201,
            jsonEncode({
              'data': {
                'id': 'review-1',
                'record_id': 'record-1',
                'kind': 'teacher_correction',
                'reason': 'Saya perlu memperbaiki keterangan.',
                'proposed_change': {
                  'operation': 'revise',
                  'description': 'Keterangan koreksi yang tepat.',
                },
                'status': 'pending',
                'created_at': '2026-10-13T08:00:00Z',
              },
            }),
          ),
        );
      final controller = TeacherCorrectionController(
        ApiClient(
          baseUrl: Uri.parse('http://localhost/api/v1'),
          transport: transport,
        ),
        recordId: 'record-1',
      );
      final review = await controller.request(
        description: 'Saya perlu memperbaiki keterangan.',
        proposedDescription: 'Keterangan koreksi yang tepat.',
      );
      expect(review.status, 'pending');
      expect(controller.recordTotalChanged, isFalse);
      expect(
        jsonDecode(
          transport.requests.single.body!,
        )['proposed_change']['operation'],
        'revise',
      );
    },
  );
}

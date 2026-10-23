import 'package:flutter/material.dart';

enum AsyncContentStatus { loading, error, empty, data }

class AsyncContent<T> extends StatelessWidget {
  const AsyncContent({
    super.key,
    required this.status,
    required this.builder,
    this.data,
    this.errorMessage = 'Tidak dapat memuat data.',
    this.emptyMessage = 'Belum ada data.',
    this.onRetry,
  });

  final AsyncContentStatus status;
  final T? data;
  final String errorMessage;
  final String emptyMessage;
  final VoidCallback? onRetry;
  final Widget Function(BuildContext context, T data) builder;

  @override
  Widget build(BuildContext context) => switch (status) {
    AsyncContentStatus.loading => Semantics(
      label: 'Memuat data',
      liveRegion: true,
      child: Center(child: CircularProgressIndicator()),
    ),
    AsyncContentStatus.error => Center(child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(errorMessage, textAlign: TextAlign.center),
        if (onRetry case final retry?) TextButton(onPressed: retry, child: const Text('Coba lagi')),
      ],
    )),
    AsyncContentStatus.empty => Center(child: Semantics(
      label: emptyMessage,
      child: Text(emptyMessage, textAlign: TextAlign.center),
    )),
    AsyncContentStatus.data => builder(context, data as T),
  };
}

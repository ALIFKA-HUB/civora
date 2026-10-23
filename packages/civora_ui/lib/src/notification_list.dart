import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class NotificationList extends StatelessWidget {
  const NotificationList({
    super.key,
    required this.items,
    required this.onOpen,
    required this.onRefresh,
    this.isLoading = false,
    this.errorMessage,
    this.onRetry,
  });

  final List<NotificationDto> items;
  final ValueChanged<NotificationDto> onOpen;
  final Future<void> Function() onRefresh;
  final bool isLoading;
  final String? errorMessage;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    if (errorMessage != null) {
      return ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(errorMessage!, textAlign: TextAlign.center),
          const SizedBox(height: 8),
          Center(
            child: TextButton(
              onPressed: onRetry,
              child: const Text('Coba lagi'),
            ),
          ),
        ],
      );
    }
    if (items.isEmpty) {
      if (isLoading) {
        return const Center(
          child: CircularProgressIndicator(semanticsLabel: 'Memuat notifikasi'),
        );
      }
      return RefreshIndicator(
        onRefresh: onRefresh,
        child: ListView(
          children: const [
            SizedBox(height: 180),
            Center(child: Text('Belum ada notifikasi.')),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final item = items[index];
          return Card(
            child: ListTile(
              leading: Icon(
                item.readAt == null
                    ? Icons.notifications_active_outlined
                    : Icons.notifications_none,
                color: item.readAt == null
                    ? Theme.of(context).colorScheme.primary
                    : null,
              ),
              title: Text(item.message),
              subtitle: Text(
                MaterialLocalizations.of(
                  context,
                ).formatMediumDate(item.createdAt.toLocal()),
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => onOpen(item),
            ),
          );
        },
      ),
    );
  }
}

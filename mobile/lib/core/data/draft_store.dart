import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../models/inventory_models.dart';
import 'local/app_database.dart';

class DraftStore {
  DraftStore(this._database);

  final AppDatabase _database;
  final Uuid _uuid = const Uuid();

  Stream<List<PendingDraft>> watchAll() =>
      (_database.select(
        _database.pendingOperations,
      )..orderBy([(row) => OrderingTerm.desc(row.updatedAt)])).watch().map(
        (rows) => rows
            .map(
              (row) => PendingDraft(
                id: row.operationId,
                type: row.type,
                title: row.title,
                lineCount: _lineCount(row.payloadJson),
                createdAt: row.createdAt,
                state: row.state,
                message: row.message,
              ),
            )
            .toList(),
      );

  Future<String> save({
    required String type,
    required String title,
    required Map<String, dynamic> payload,
    String? operationId,
  }) async {
    final id = operationId ?? _uuid.v4();
    final now = DateTime.now();
    await _database
        .into(_database.pendingOperations)
        .insert(
          PendingOperationsCompanion.insert(
            operationId: id,
            type: type,
            title: title,
            payloadJson: jsonEncode(payload),
            createdAt: now,
            updatedAt: now,
          ),
        );
    return id;
  }

  Future<Map<String, dynamic>?> payload(String id) async {
    final row = await (_database.select(
      _database.pendingOperations,
    )..where((entry) => entry.operationId.equals(id))).getSingleOrNull();
    if (row == null) return null;
    return Map<String, dynamic>.from(jsonDecode(row.payloadJson) as Map);
  }

  Future<void> updateState(String id, String state, {String? message}) =>
      (_database.update(
        _database.pendingOperations,
      )..where((row) => row.operationId.equals(id))).write(
        PendingOperationsCompanion(
          state: Value(state),
          message: Value(message),
          updatedAt: Value(DateTime.now()),
        ),
      );

  Future<void> delete(String id) => (_database.delete(
    _database.pendingOperations,
  )..where((row) => row.operationId.equals(id))).go();

  int _lineCount(String payloadJson) {
    try {
      final payload = jsonDecode(payloadJson) as Map<String, dynamic>;
      return (payload['lines'] as List?)?.length ?? 0;
    } catch (_) {
      return 0;
    }
  }
}

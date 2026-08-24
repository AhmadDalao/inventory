import 'package:uuid/uuid.dart';

import '../api/api_client.dart';
import '../api/mobile_session_store.dart';
import '../config/app_config.dart';
import '../models/inventory_models.dart';
import 'inventory_repository.dart';

class ApiInventoryRepository implements InventoryRepository {
  ApiInventoryRepository(this._api, this._sessionStore);

  final ApiClient _api;
  final MobileSessionStore _sessionStore;
  final Uuid _uuid = const Uuid();
  int _syncCursor = 0;

  @override
  Future<void> login(
    String email,
    String password, {
    required bool keepSignedIn,
  }) async {
    final deviceId = await _sessionStore.deviceId ?? _uuid.v4();
    final data = await _api.post(
      '/auth/login',
      data: {
        'email': email,
        'password': password,
        'device_uuid': deviceId,
        'device_name': 'Inventory KONA Mobile',
        'platform': _platform,
        'app_version': AppConfig.appVersion,
      },
    );
    await _sessionStore.save(
      accessToken: data['access_token'] as String,
      refreshToken: data['refresh_token'] as String,
      deviceId: deviceId,
      keepSignedIn: keepSignedIn,
      email: email,
    );
  }

  @override
  Future<void> verifyPassword(String password) async {
    await _api.post('/me/verify-password', data: {'password': password});
  }

  @override
  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } finally {
      await _sessionStore.clear();
    }
  }

  @override
  Future<MobileBootstrap> bootstrap() async {
    final envelope = await _api.getEnvelope('/bootstrap');
    final data = envelope.data;
    _syncCursor = (envelope.meta['sync_cursor'] as num? ?? 0).toInt();
    final user = data['user'] as Map<String, dynamic>? ?? const {};
    final storages = ((data['storages'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(StorageLocation.fromJson)
        .toList();
    final items = _items(data['items']);
    final tasks = ((data['tasks'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(MobileTask.fromJson)
        .toList();
    final recipients = ((data['recipients'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(MobileRecipient.fromJson)
        .toList();
    final manager = data['manager'] is Map
        ? MobileManager.fromJson(
            Map<String, dynamic>.from(data['manager'] as Map),
          )
        : null;
    return MobileBootstrap(
      userName: user['name'] as String? ?? 'Employee',
      storages: storages,
      items: items,
      tasks: tasks,
      capabilities: Set<String>.from(
        (data['capabilities'] as List?) ?? const [],
      ),
      permissions: Set<String>.from((data['permissions'] as List?) ?? const []),
      recipients: recipients,
      settings: Map<String, dynamic>.from(data['settings'] as Map? ?? const {}),
      manager: manager,
    );
  }

  @override
  Future<MobileSyncDelta> sync() async {
    final envelope = await _api.getEnvelope(
      '/sync',
      query: {'after': _syncCursor},
    );
    final data = envelope.data;
    final meta = envelope.meta;
    final fullResync = data['full_resync_required'] == true;
    final nextCursor = (meta['next_cursor'] as num? ?? _syncCursor).toInt();
    final latestCursor = (meta['sync_cursor'] as num? ?? nextCursor).toInt();
    _syncCursor = fullResync ? latestCursor : nextCursor;
    return MobileSyncDelta(
      nextCursor: nextCursor,
      latestCursor: latestCursor,
      hasMore: meta['has_more'] == true,
      fullResyncRequired: fullResync,
      items: _items(data['items']),
      deletedItemIds: ((data['deleted_item_ids'] as List?) ?? const [])
          .map((value) => value is Map ? value['item_id'] : value)
          .whereType<num>()
          .map((value) => value.toInt())
          .toSet(),
      tasks: ((data['tasks'] as List?) ?? const [])
          .whereType<Map>()
          .map((entry) => MobileTask.fromJson(Map<String, dynamic>.from(entry)))
          .toList(),
      permissions: Set<String>.from((data['permissions'] as List?) ?? const []),
      capabilities: Set<String>.from(
        (data['capabilities'] as List?) ?? const [],
      ),
      storageIds: ((data['storage_ids'] as List?) ?? const [])
          .whereType<num>()
          .map((value) => value.toInt())
          .toSet(),
      tasksChanged: data['tasks_changed'] == true,
      accessFingerprint: meta['access_fingerprint'] as String? ?? '',
    );
  }

  @override
  Future<List<InventoryItem>> searchItems(
    String query, {
    int? storageId,
  }) async {
    final data = await _api.get(
      '/items/lookup',
      query: {'q': query, 'storage_id': ?storageId},
    );
    return _items(data['items'], storageId: storageId);
  }

  @override
  Future<List<MobileOperation>> operations() async {
    final data = await _api.get('/operations/mine');
    return ((data['items'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(MobileOperation.fromJson)
        .toList();
  }

  @override
  Future<List<MobileTask>> handovers() async {
    final data = await _api.get('/handovers/mine');
    return ((data['items'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(MobileTask.fromJson)
        .toList();
  }

  @override
  Future<HandoverDetail> handover(int id) async {
    final data = await _api.get('/handovers/$id');
    return HandoverDetail.fromJson(data);
  }

  @override
  Future<OperationReceipt> submitUsage({
    required int storageId,
    required List<CartLine> lines,
    required String defaultReason,
    String? defaultCustomReason,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    final payload = {
      'client_operation_id': clientOperationId ?? _api.operationId(),
      'lines': lines.map((line) {
        final reason = UsageReason.normalizeCode(
          line.reasonCode ?? defaultReason,
        );
        final customReason = line.reasonCode == null
            ? defaultCustomReason
            : line.customReason;
        return {
          'type': 'usage',
          'item_id': line.item.id,
          'storage_id': storageId,
          'input_quantity': line.quantity,
          'package_preset_id': line.packagePresetId,
          'expected_balance': line.expectedBalance ?? line.item.quantity,
          'reason': reason,
          'custom_reason': reason == 'other' ? customReason : null,
          'notes': notes,
        };
      }).toList(),
    };
    final data = proofPath == null
        ? await _api.post('/movements/batch', data: payload)
        : await _api.postMultipart(
            '/movements/batch',
            fields: payload,
            filePath: proofPath,
            fileField: 'proof_image',
          );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> submitRestock({
    required int storageId,
    required List<CartLine> lines,
    String? reference,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    final payload = {
      'client_operation_id': clientOperationId ?? _api.operationId(),
      'lines': lines
          .map(
            (line) => {
              'type': 'restock',
              'item_id': line.item.id,
              'storage_id': storageId,
              'input_quantity': line.quantity,
              'package_preset_id': line.packagePresetId,
              'expected_balance': line.expectedBalance ?? line.item.quantity,
              'reference': reference,
              'notes': notes,
            },
          )
          .toList(),
    };
    final data = proofPath == null
        ? await _api.post('/movements/batch', data: payload)
        : await _api.postMultipart(
            '/movements/batch',
            fields: payload,
            filePath: proofPath,
            fileField: 'proof_image',
          );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> createHandover({
    required String purpose,
    required int sourceStorageId,
    int? destinationStorageId,
    int? recipientUserId,
    required List<CartLine> lines,
    String? clientOperationId,
  }) async {
    final data = await _api.post(
      '/handovers',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'purpose': purpose,
        'source_storage_id': sourceStorageId,
        'destination_storage_id': destinationStorageId,
        'recipient_user_id': recipientUserId,
        'lines': lines
            .map(
              (line) => {
                'item_id': line.item.id,
                'quantity': line.pieceQuantity,
                'expected_balance': line.expectedBalance ?? line.item.quantity,
              },
            )
            .toList(),
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> confirmReceipt(
    int handoverId,
    Map<int, double> quantities, {
    String? clientOperationId,
  }) async {
    final data = await _api.post(
      '/handovers/$handoverId/receipt',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'received_quantities': {
          for (final entry in quantities.entries)
            entry.key.toString(): entry.value,
        },
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> confirmReceiptReview(
    int handoverId,
    Map<int, double> quantities, {
    String? notes,
    String? clientOperationId,
  }) async {
    final data = await _api.post(
      '/handovers/$handoverId/confirm-receipt',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'received_quantities': {
          for (final entry in quantities.entries)
            entry.key.toString(): entry.value,
        },
        'receipt_notes': notes,
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> submitCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    final payload = <String, dynamic>{
      'client_operation_id': clientOperationId ?? _api.operationId(),
      'returned_quantities': {
        for (final entry in returnedQuantities.entries)
          entry.key.toString(): entry.value,
      },
      'reconciliations': _reconciliationRows(
        reconciliations,
        discrepancyNotes: discrepancyNotes,
      ),
      'close_notes': notes,
    };
    final data = proofPath == null
        ? await _api.post('/handovers/$handoverId/closeout', data: payload)
        : await _api.postMultipart(
            '/handovers/$handoverId/closeout',
            fields: payload,
            filePath: proofPath,
            fileField: 'proof_image',
          );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> approveCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    Map<String, String> varianceReasons = const {},
    Map<String, String> varianceNotes = const {},
    String? notes,
    String? clientOperationId,
  }) async {
    final data = await _api.post(
      '/handovers/$handoverId/approve-closeout',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'returned_quantities': {
          for (final entry in returnedQuantities.entries)
            entry.key.toString(): entry.value,
        },
        'reconciliations': _reconciliationRows(
          reconciliations,
          discrepancyNotes: discrepancyNotes,
          varianceReasons: varianceReasons,
          varianceNotes: varianceNotes,
        ),
        'approval_notes': notes,
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> decideRequest(
    int handoverId, {
    required bool approve,
    String? notes,
    String? clientOperationId,
  }) async {
    final action = approve ? 'approve-request' : 'reject-request';
    final data = await _api.post(
      '/handovers/$handoverId/$action',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'notes': notes,
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> cancelHandover(
    int handoverId, {
    String? notes,
    String? clientOperationId,
  }) async {
    final data = await _api.post(
      '/handovers/$handoverId/cancel',
      data: {
        'client_operation_id': clientOperationId ?? _api.operationId(),
        'notes': notes,
      },
    );
    return _receipt(data);
  }

  @override
  Future<OperationReceipt> submitCustodyReturn({
    required int handoverId,
    required List<CustodyReturnLine> lines,
    String? notes,
    Map<int, String> damageProofPaths = const {},
    String? clientOperationId,
  }) async {
    final payload = <String, dynamic>{
      'client_operation_id': clientOperationId ?? _api.operationId(),
      'lines': lines.map((line) => line.toJson()).toList(),
      'notes': notes,
    };
    final files = <String, String>{
      for (final entry in damageProofPaths.entries)
        'damage_proof_${entry.key}': entry.value,
    };
    final data = files.isEmpty
        ? await _api.post(
            '/handovers/$handoverId/custody-returns',
            data: payload,
          )
        : await _api.postMultipartFiles(
            '/handovers/$handoverId/custody-returns',
            fields: payload,
            files: files,
          );
    return _receipt(data);
  }

  List<InventoryItem> _items(Object? raw, {int? storageId}) =>
      ((raw as List?) ?? const [])
          .whereType<Map>()
          .expand(
            (entry) => InventoryItem.expandJson(
              Map<String, dynamic>.from(entry),
              storageId: storageId,
            ),
          )
          .toList();

  String get _platform {
    // The API only needs the native platform family, not Flutter as a framework name.
    return const String.fromEnvironment(
      'MOBILE_PLATFORM',
      defaultValue: 'android',
    );
  }

  List<Map<String, dynamic>> _reconciliationRows(
    Map<String, Map<String, double>> values, {
    Map<String, String> discrepancyNotes = const {},
    Map<String, String> varianceReasons = const {},
    Map<String, String> varianceNotes = const {},
  }) => [
    for (final entry in values.entries)
      {
        'unit': entry.key,
        'reasons': entry.value,
        'discrepancy_notes': discrepancyNotes[entry.key],
        'variance_reason_code': varianceReasons[entry.key],
        'variance_notes': varianceNotes[entry.key],
      },
  ];

  OperationReceipt _receipt(Map<String, dynamic> data) => OperationReceipt(
    reference:
        data['reference'] as String? ??
        data['handover_number'] as String? ??
        data['operation_id']?.toString() ??
        '',
    status: data['status'] as String? ?? 'completed',
    message: data['message'] as String?,
  );
}

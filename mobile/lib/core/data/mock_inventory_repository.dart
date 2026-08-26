import 'dart:async';

import '../api/api_client.dart';
import 'inventory_repository.dart';
import '../models/inventory_models.dart';

class MockInventoryRepository implements InventoryRepository {
  @override
  String? get bootstrapAccessFingerprint => 'mock-access';

  final _storages = const [
    StorageLocation(
      id: 1,
      name: 'KONA Main',
      isDefault: true,
      usageProfile: 'wristband',
    ),
    StorageLocation(id: 2, name: 'KONA Office', usageProfile: 'general'),
    StorageLocation(id: 3, name: 'Event Store', usageProfile: 'wristband'),
  ];

  late final List<InventoryItem> _items = [
    const InventoryItem(
      id: 15,
      name: 'Blue Wristband',
      sku: 'WB-BLUE',
      barcode: '62811001',
      unit: 'pcs',
      quantity: 246,
      storageId: 1,
      storageName: 'KONA Main',
      packagePresets: [
        ItemPackagePreset(
          id: 101,
          label: 'Bag',
          piecesPerUnit: 10,
          isDefault: true,
        ),
        ItemPackagePreset(id: 102, label: 'Box', piecesPerUnit: 50),
      ],
    ),
    const InventoryItem(
      id: 16,
      name: 'Dark Red Wristband',
      sku: 'WB-DARK-RED',
      barcode: '62811002',
      unit: 'pcs',
      quantity: 310,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 17,
      name: 'Green Wristband',
      sku: 'WB-GREEN',
      barcode: '62811003',
      unit: 'pcs',
      quantity: 314,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 18,
      name: 'Yellow-Blue Wristband',
      sku: 'WB-YELLOW-BLUE',
      barcode: '62811004',
      unit: 'pcs',
      quantity: 223,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 19,
      name: 'Cleaning Gloves',
      sku: 'CL-GLOVE-M',
      barcode: '62812001',
      unit: 'pairs',
      quantity: 48,
      storageId: 2,
      storageName: 'KONA Office',
    ),
    const InventoryItem(
      id: 20,
      name: 'Guest Towels',
      sku: 'GT-TOWEL-W',
      barcode: '62812002',
      unit: 'pcs',
      quantity: 72,
      storageId: 2,
      storageName: 'KONA Office',
    ),
  ];

  final List<MobileTask> _tasks = [
    const MobileTask(
      id: 101,
      reference: 'HDO-20260810-7A21',
      title: 'Confirm wristband receipt',
      status: 'awaiting_receipt',
      purpose: 'temporary_use',
      itemCount: 3,
      quantity: 326,
      source: 'KONA Main',
      allowedActions: {'confirm_receipt'},
      requiresAction: true,
    ),
    const MobileTask(
      id: 102,
      reference: 'HDO-20260810-9F42',
      title: 'Office transfer arriving',
      status: 'awaiting_receipt',
      purpose: 'storage_transfer',
      itemCount: 2,
      quantity: 80,
      source: 'KONA Main',
      destination: 'KONA Office',
      allowedActions: {'confirm_receipt'},
      requiresAction: true,
    ),
    const MobileTask(
      id: 103,
      reference: 'HDO-20260807-1C55',
      title: 'Cleaning crew custody',
      status: 'delivered',
      purpose: 'staff_custody',
      itemCount: 2,
      quantity: 8,
      source: 'KONA Office',
      allowedActions: {'return_custody'},
      requiresAction: true,
    ),
  ];

  final Map<int, Map<int, double>> _receivedQuantities = {};
  final Map<int, Map<int, double>> _returnedQuantities = {};
  final Map<int, Map<String, Map<String, double>>> _reconciliations = {};

  Future<void> _wait() =>
      Future<void>.delayed(const Duration(milliseconds: 350));

  @override
  Future<void> login(
    String email,
    String password, {
    required bool keepSignedIn,
  }) async => _wait();

  @override
  Future<void> verifyPassword(String password) async {
    await _wait();
    if (password != 'mock-password') {
      throw const ApiFailure('password_incorrect', 'Password is incorrect.');
    }
  }

  @override
  Future<void> logout() async => _wait();

  @override
  Future<MobileBootstrap> bootstrap() async {
    await _wait();
    return MobileBootstrap(
      userName: 'Alaa',
      storages: _storages,
      items: List.unmodifiable(_items),
      tasks: List.unmodifiable(_tasks),
      capabilities: const {
        'usage',
        'restock',
        'transfer',
        'handover',
        'custody',
      },
      permissions: const {
        'mobile.access',
        'storages.view',
        'items.view',
        'movements.usage',
        'movements.restock',
        'handovers.view',
        'handovers.create',
        'handovers.request',
        'handovers.close',
        'handovers.approve',
        'handovers.custody_return',
      },
      recipients: const [
        MobileRecipient(id: 7, name: 'Alaa', position: 'Reception Staff'),
        MobileRecipient(id: 8, name: 'Khalid', position: 'Operations Staff'),
        MobileRecipient(id: 9, name: 'Mona', position: 'Cleaning Crew'),
      ],
      settings: const {
        'manual_restock_enabled': true,
        'offline_drafts_enabled': true,
        'require_usage_proof': false,
        'min_supported_version': '1.0.0',
        'usage_reasons': [
          {
            'code': 'online',
            'label': 'Online',
            'active': true,
            'sort_order': 1,
            'requires_custom_text': false,
          },
          {
            'code': 'walkin',
            'label': 'Walk-in',
            'active': true,
            'sort_order': 2,
            'requires_custom_text': false,
          },
          {
            'code': 'event',
            'label': 'Event',
            'active': true,
            'sort_order': 3,
            'requires_custom_text': false,
          },
          {
            'code': 'damage',
            'label': 'Damage',
            'active': true,
            'sort_order': 4,
            'requires_custom_text': false,
          },
          {
            'code': 'sport',
            'label': 'Sport',
            'active': true,
            'sort_order': 5,
            'requires_custom_text': false,
          },
          {
            'code': 'school',
            'label': 'School',
            'active': true,
            'sort_order': 6,
            'requires_custom_text': false,
          },
          {
            'code': 'complimentary',
            'label': 'Complimentary',
            'active': true,
            'sort_order': 7,
            'requires_custom_text': false,
          },
          {
            'code': 'no_show',
            'label': 'No Show',
            'active': true,
            'sort_order': 8,
            'requires_custom_text': false,
          },
          {
            'code': 'other',
            'label': 'Other',
            'active': true,
            'sort_order': 9,
            'requires_custom_text': true,
          },
        ],
        'usage_reason_catalogs': {
          'wristband': [
            {
              'code': 'online',
              'label': 'Online',
              'active': true,
              'sort_order': 1,
              'requires_custom_text': false,
            },
            {
              'code': 'walkin',
              'label': 'Walk-in',
              'active': true,
              'sort_order': 2,
              'requires_custom_text': false,
            },
            {
              'code': 'event',
              'label': 'Event',
              'active': true,
              'sort_order': 3,
              'requires_custom_text': false,
            },
            {
              'code': 'damage',
              'label': 'Damage',
              'active': true,
              'sort_order': 4,
              'requires_custom_text': false,
            },
            {
              'code': 'sport',
              'label': 'Sport',
              'active': true,
              'sort_order': 5,
              'requires_custom_text': false,
            },
            {
              'code': 'school',
              'label': 'School',
              'active': true,
              'sort_order': 6,
              'requires_custom_text': false,
            },
            {
              'code': 'complimentary',
              'label': 'Complimentary',
              'active': true,
              'sort_order': 7,
              'requires_custom_text': false,
            },
            {
              'code': 'no_show',
              'label': 'No Show',
              'active': true,
              'sort_order': 8,
              'requires_custom_text': false,
            },
            {
              'code': 'other',
              'label': 'Other',
              'active': true,
              'sort_order': 9,
              'requires_custom_text': true,
            },
          ],
          'general': [
            {
              'code': 'cleaning',
              'label': 'Cleaning',
              'active': true,
              'sort_order': 1,
              'requires_custom_text': false,
            },
            {
              'code': 'operations',
              'label': 'Operations',
              'active': true,
              'sort_order': 2,
              'requires_custom_text': false,
            },
            {
              'code': 'maintenance',
              'label': 'Maintenance',
              'active': true,
              'sort_order': 3,
              'requires_custom_text': false,
            },
            {
              'code': 'event',
              'label': 'Event',
              'active': true,
              'sort_order': 4,
              'requires_custom_text': false,
            },
            {
              'code': 'damage',
              'label': 'Damage',
              'active': true,
              'sort_order': 5,
              'requires_custom_text': false,
            },
            {
              'code': 'department_supplies',
              'label': 'Department Supplies',
              'active': true,
              'sort_order': 6,
              'requires_custom_text': false,
            },
            {
              'code': 'other',
              'label': 'Other',
              'active': true,
              'sort_order': 7,
              'requires_custom_text': true,
            },
          ],
        },
      },
    );
  }

  @override
  Future<List<InventoryItem>> searchItems(
    String query, {
    int? storageId,
  }) async {
    await _wait();
    final normalized = query.trim().toLowerCase();
    return _items.where((item) {
      final inStorage = storageId == null || item.storageId == storageId;
      final matches =
          normalized.isEmpty ||
          '${item.name} ${item.sku} ${item.barcode ?? ''}'
              .toLowerCase()
              .contains(normalized);
      return inStorage && matches;
    }).toList();
  }

  @override
  Future<List<MobileOperation>> operations() async {
    await _wait();
    final now = DateTime.now();
    return [
      MobileOperation(
        id: 3,
        clientOperationId: 'mock-usage-3',
        type: 'movement_batch',
        status: 'succeeded',
        reference: 'MOV-MOCK-103',
        message: 'Usage accepted by the server.',
        createdAt: now.subtract(const Duration(minutes: 12)),
        completedAt: now.subtract(const Duration(minutes: 12)),
      ),
      MobileOperation(
        id: 2,
        clientOperationId: 'mock-handover-2',
        type: 'handover_create',
        status: 'succeeded',
        reference: 'HDO-MOCK-102',
        message: 'Handover created.',
        createdAt: now.subtract(const Duration(hours: 2)),
        completedAt: now.subtract(const Duration(hours: 2)),
      ),
      MobileOperation(
        id: 1,
        clientOperationId: 'mock-conflict-1',
        type: 'movement_batch',
        status: 'failed',
        message: 'Balance changed. Review the latest quantity.',
        createdAt: now.subtract(const Duration(days: 1)),
        completedAt: now.subtract(const Duration(days: 1)),
      ),
    ];
  }

  @override
  Future<List<MobileTask>> handovers() async {
    await _wait();
    return List.unmodifiable(_tasks);
  }

  @override
  Future<HandoverDetail> handover(int id) async {
    await _wait();
    return _handoverDetail(id);
  }

  HandoverDetail _handoverDetail(int id) {
    final task = _tasks.firstWhere((entry) => entry.id == id);
    final matching = _items.take(task.itemCount).toList();
    final issuedQuantities = _distributedMockQuantities(task, matching);
    final received = _receivedQuantities[id] ?? const <int, double>{};
    final returned = _returnedQuantities[id] ?? const <int, double>{};
    final storedReconciliations =
        _reconciliations[id] ?? const <String, Map<String, double>>{};
    final lines = <HandoverLine>[
      for (var index = 0; index < matching.length; index++)
        _mockHandoverLine(
          handoverId: id,
          item: matching[index],
          issued: issuedQuantities[index],
          task: task,
          received: received,
          returned: returned,
          index: index,
        ),
    ];
    return HandoverDetail(
      task: task,
      recipientName: 'Alaa',
      issuerName: 'Ahmad Dalao',
      scheduledForDate: '2026-08-10',
      reviewDate: task.purpose == 'staff_custody' ? '2026-10-10' : null,
      lines: lines,
      reconciliations: [
        for (final entry in storedReconciliations.entries)
          HandoverReconciliation(
            unit: entry.key,
            reasons: Map.unmodifiable(entry.value),
            returnedTotal: lines
                .where((line) => line.unit == entry.key)
                .fold<double>(0, (sum, line) => sum + line.quantityReturned),
            difference: _reconciliationDifference(
              lines.where((line) => line.unit == entry.key),
              entry.value,
            ),
          ),
      ],
    );
  }

  HandoverLine _mockHandoverLine({
    required int handoverId,
    required InventoryItem item,
    required double issued,
    required MobileTask task,
    required Map<int, double> received,
    required Map<int, double> returned,
    required int index,
  }) {
    final lineId = handoverId * 10 + index;
    final receivedQuantity =
        received[lineId] ??
        (task.status == 'awaiting_receipt' || task.status == 'requested'
            ? 0
            : issued);
    final returnedQuantity = returned[lineId] ?? 0;
    final usedQuantity = returned.containsKey(lineId)
        ? (receivedQuantity - returnedQuantity)
              .clamp(0, receivedQuantity)
              .toDouble()
        : 0.0;
    final heldQuantity = task.purpose == 'staff_custody'
        ? (receivedQuantity - returnedQuantity)
              .clamp(0, receivedQuantity)
              .toDouble()
        : 0.0;
    return HandoverLine(
      id: lineId,
      itemId: item.id,
      name: item.name,
      sku: item.sku,
      barcode: item.barcode,
      unit: item.unit,
      imageUrl: item.imageUrl,
      quantityIssued: issued,
      quantityReceived: receivedQuantity,
      quantityUsed: usedQuantity,
      quantityReturned: returnedQuantity,
      quantityHeld: heldQuantity,
    );
  }

  double _reconciliationDifference(
    Iterable<HandoverLine> lines,
    Map<String, double> reasons,
  ) {
    final physicalUsed = lines.fold<double>(
      0,
      (sum, line) => sum + line.quantityUsed,
    );
    final online = reasons['online'] ?? 0;
    final noShow = reasons['no_show'] ?? reasons['noshow'] ?? 0;
    final operationalUsed =
        reasons.entries.fold<double>(0, (sum, entry) {
          if (entry.key == 'online' ||
              entry.key == 'no_show' ||
              entry.key == 'noshow') {
            return sum;
          }
          return sum + entry.value;
        }) +
        online -
        noShow;
    return physicalUsed - operationalUsed;
  }

  void _replaceTask(
    int id, {
    required String status,
    required Set<String> allowedActions,
    bool? requiresAction,
  }) {
    final index = _tasks.indexWhere((entry) => entry.id == id);
    final task = _tasks[index];
    _tasks[index] = MobileTask(
      id: task.id,
      reference: task.reference,
      title: task.title,
      status: status,
      purpose: task.purpose,
      itemCount: task.itemCount,
      quantity: task.quantity,
      source: task.source,
      destination: task.destination,
      allowedActions: allowedActions,
      requiresAction: requiresAction ?? allowedActions.isNotEmpty,
    );
  }

  ({String status, Set<String> actions}) _postReceiptState(MobileTask task) =>
      switch (task.purpose) {
        'storage_transfer' => (status: 'closed', actions: const <String>{}),
        'staff_custody' => (
          status: 'delivered',
          actions: const {'return_custody'},
        ),
        _ => (status: 'delivered', actions: const {'report_closeout'}),
      };

  bool _receiptMatchesIssued(
    HandoverDetail detail,
    Map<int, double> quantities,
  ) => detail.lines.every(
    (line) =>
        ((quantities[line.id] ?? 0) - line.quantityIssued).abs() < 0.000001,
  );

  List<double> _distributedMockQuantities(
    MobileTask task,
    List<InventoryItem> items,
  ) {
    if (items.isEmpty) return const [];

    final wholeNumberUnits = items.every(
      (item) => const {
        'pcs',
        'piece',
        'pieces',
        'pair',
        'pairs',
        'roll',
        'rolls',
      }.contains(item.unit.toLowerCase()),
    );
    if (!wholeNumberUnits || task.quantity != task.quantity.roundToDouble()) {
      return List<double>.filled(items.length, task.quantity / items.length);
    }

    final total = task.quantity.toInt();
    final base = total ~/ items.length;
    final remainder = total.remainder(items.length);
    return List<double>.generate(
      items.length,
      (index) => (base + (index < remainder ? 1 : 0)).toDouble(),
    );
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
    await _wait();
    return OperationReceipt(
      reference: 'MOV-MOCK-${DateTime.now().millisecondsSinceEpoch}',
      status: 'completed',
      message: '${lines.length} lines queued safely.',
    );
  }

  @override
  Future<MobileSyncDelta> sync() async {
    await _wait();
    return const MobileSyncDelta(
      nextCursor: 0,
      latestCursor: 0,
      hasMore: false,
      fullResyncRequired: false,
      items: [],
      deletedItemIds: {},
      tasks: [],
      permissions: {},
      capabilities: {},
      storageIds: {},
      accessFingerprint: 'mock-access',
    );
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
    await _wait();
    return OperationReceipt(
      reference:
          reference ?? 'RST-MOCK-${DateTime.now().millisecondsSinceEpoch}',
      status: 'completed',
    );
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
    await _wait();
    final id =
        _tasks.fold<int>(0, (max, task) => task.id > max ? task.id : max) + 1;
    final source = _storageName(sourceStorageId);
    final destination = destinationStorageId == null
        ? null
        : _storageName(destinationStorageId);
    final reference = 'HDO-MOCK-$id';
    _tasks.insert(
      0,
      MobileTask(
        id: id,
        reference: reference,
        title: purpose == 'storage_transfer'
            ? 'Storage transfer request'
            : purpose == 'staff_custody'
            ? 'Long-term custody request'
            : 'Temporary handover request',
        status: 'requested',
        purpose: purpose,
        itemCount: lines.length,
        quantity: lines.fold<double>(0, (sum, line) => sum + line.baseQuantity),
        source: source,
        destination: destination,
        allowedActions: const {'approve_request', 'reject_request'},
        requiresAction: true,
      ),
    );
    return OperationReceipt(reference: reference, status: 'requested');
  }

  String? _storageName(int storageId) {
    for (final storage in _storages) {
      if (storage.id == storageId) return storage.name;
    }
    return null;
  }

  @override
  Future<OperationReceipt> confirmReceipt(
    int handoverId,
    Map<int, double> quantities, {
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    final detail = _handoverDetail(handoverId);
    _receivedQuantities[handoverId] = Map<int, double>.from(quantities);
    if (!_receiptMatchesIssued(detail, quantities)) {
      _replaceTask(
        handoverId,
        status: 'receipt_review',
        allowedActions: const {'review_receipt'},
      );
      return OperationReceipt(
        reference: detail.task.reference,
        status: 'receipt_review',
        message: 'Receipt difference submitted for issuer review.',
      );
    }
    final next = _postReceiptState(detail.task);
    _replaceTask(handoverId, status: next.status, allowedActions: next.actions);
    return OperationReceipt(
      reference: detail.task.reference,
      status: next.status,
      message: 'Receipt quantities submitted.',
    );
  }

  @override
  Future<OperationReceipt> confirmReceiptReview(
    int handoverId,
    Map<int, double> quantities, {
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    final detail = _handoverDetail(handoverId);
    _receivedQuantities[handoverId] = Map<int, double>.from(quantities);
    final next = _postReceiptState(detail.task);
    _replaceTask(handoverId, status: next.status, allowedActions: next.actions);
    return OperationReceipt(
      reference: detail.task.reference,
      status: next.status,
      message: 'Receipt difference approved.',
    );
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
    await _wait();
    final detail = _handoverDetail(handoverId);
    _returnedQuantities[handoverId] = Map<int, double>.from(returnedQuantities);
    _reconciliations[handoverId] = {
      for (final entry in reconciliations.entries)
        entry.key: Map<String, double>.from(entry.value),
    };
    _replaceTask(
      handoverId,
      status: 'pending_approval',
      allowedActions: const {'approve_closeout'},
    );
    return OperationReceipt(
      reference: detail.task.reference,
      status: 'pending_approval',
      message: 'Closeout submitted for issuer approval.',
    );
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
    await _wait();
    final detail = _handoverDetail(handoverId);
    _returnedQuantities[handoverId] = Map<int, double>.from(returnedQuantities);
    _reconciliations[handoverId] = {
      for (final entry in reconciliations.entries)
        entry.key: Map<String, double>.from(entry.value),
    };
    _replaceTask(
      handoverId,
      status: 'closed',
      allowedActions: const {},
      requiresAction: false,
    );
    return OperationReceipt(
      reference: detail.task.reference,
      status: 'closed',
      message: 'Final stock approved.',
    );
  }

  @override
  Future<OperationReceipt> decideRequest(
    int handoverId, {
    required bool approve,
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    final task = _tasks.firstWhere((entry) => entry.id == handoverId);
    if (approve) {
      _replaceTask(
        handoverId,
        status: 'awaiting_receipt',
        allowedActions: const {'confirm_receipt'},
      );
    } else {
      _replaceTask(
        handoverId,
        status: 'rejected',
        allowedActions: const {},
        requiresAction: false,
      );
    }
    return OperationReceipt(
      reference: task.reference,
      status: approve ? 'awaiting_receipt' : 'rejected',
    );
  }

  @override
  Future<OperationReceipt> cancelHandover(
    int handoverId, {
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    final task = _tasks.firstWhere((entry) => entry.id == handoverId);
    _replaceTask(
      handoverId,
      status: 'cancelled',
      allowedActions: const {},
      requiresAction: false,
    );
    return OperationReceipt(reference: task.reference, status: 'cancelled');
  }

  @override
  Future<OperationReceipt> submitCustodyReturn({
    required int handoverId,
    required List<CustodyReturnLine> lines,
    String? notes,
    Map<int, String> damageProofPaths = const {},
    String? clientOperationId,
  }) async {
    await _wait();
    final task = _tasks.firstWhere((entry) => entry.id == handoverId);
    final detail = _handoverDetail(handoverId);
    if (lines.isEmpty ||
        lines.fold<double>(0, (sum, line) => sum + line.total) <= 0.009) {
      throw ArgumentError(
        'Enter at least one serviceable, damaged, consumed, or lost quantity.',
      );
    }
    for (final line in lines) {
      final handoverLine = detail.lines.firstWhere(
        (entry) => entry.id == line.handoverLineId,
        orElse: () => throw ArgumentError(
          'A submitted line does not belong to this handover.',
        ),
      );
      if ([
        line.serviceable,
        line.damaged,
        line.consumed,
        line.lost,
      ].any((value) => value < 0)) {
        throw ArgumentError(
          '${handoverLine.name}: quantities cannot be negative.',
        );
      }
      if (line.total > handoverLine.quantityHeld + 0.009) {
        throw ArgumentError(
          '${handoverLine.name}: return outcomes exceed the quantity still held.',
        );
      }
      if (line.damaged > 0 &&
          !damageProofPaths.containsKey(line.handoverLineId)) {
        throw ArgumentError(
          '${handoverLine.name}: add a proof image for damaged stock.',
        );
      }
      if (line.lost > 0 &&
          (line.notes?.trim().isEmpty ?? true) &&
          (notes?.trim().isEmpty ?? true)) {
        throw ArgumentError(
          '${handoverLine.name}: explain the lost or missing quantity.',
        );
      }
    }
    _replaceTask(
      handoverId,
      status: 'pending_approval',
      allowedActions: const {},
      requiresAction: false,
    );
    return OperationReceipt(
      reference: task.reference,
      status: 'pending_approval',
      message: 'Custody return submitted.',
    );
  }
}

import '../models/inventory_models.dart';

abstract class InventoryRepository {
  Future<void> login(
    String email,
    String password, {
    required bool keepSignedIn,
  });
  Future<void> verifyPassword(String password);
  Future<void> logout();
  Future<MobileBootstrap> bootstrap();
  Future<MobileSyncDelta> sync();
  Future<List<InventoryItem>> searchItems(String query, {int? storageId});
  Future<List<MobileOperation>> operations();
  Future<List<MobileTask>> handovers();
  Future<HandoverDetail> handover(int id);
  Future<OperationReceipt> submitUsage({
    required int storageId,
    required List<CartLine> lines,
    required String defaultReason,
    String? defaultCustomReason,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  });
  Future<OperationReceipt> submitRestock({
    required int storageId,
    required List<CartLine> lines,
    String? reference,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  });
  Future<OperationReceipt> createHandover({
    required String purpose,
    required int sourceStorageId,
    int? destinationStorageId,
    int? recipientUserId,
    required List<CartLine> lines,
    String? clientOperationId,
  });
  Future<OperationReceipt> confirmReceipt(
    int handoverId,
    Map<int, double> quantities, {
    String? clientOperationId,
  });
  Future<OperationReceipt> confirmReceiptReview(
    int handoverId,
    Map<int, double> quantities, {
    String? notes,
    String? clientOperationId,
  });
  Future<OperationReceipt> submitCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    String? notes,
    String? proofPath,
    String? clientOperationId,
  });
  Future<OperationReceipt> approveCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    Map<String, String> varianceReasons = const {},
    Map<String, String> varianceNotes = const {},
    String? notes,
    String? clientOperationId,
  });
  Future<OperationReceipt> decideRequest(
    int handoverId, {
    required bool approve,
    String? notes,
    String? clientOperationId,
  });
  Future<OperationReceipt> cancelHandover(
    int handoverId, {
    String? notes,
    String? clientOperationId,
  });
  Future<OperationReceipt> submitCustodyReturn({
    required int handoverId,
    required List<CustodyReturnLine> lines,
    String? notes,
    Map<int, String> damageProofPaths = const {},
    String? clientOperationId,
  });
}

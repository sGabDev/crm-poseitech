export async function resolveDestination(socket, phone) {
  const [result] = await socket.onWhatsApp(phone);
  if (!result?.exists || !result.jid) {
    const error = new Error('Number is not registered');
    error.status = 422;
    throw error;
  }
  return result.jid;
}

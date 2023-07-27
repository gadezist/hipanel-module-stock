import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/utils/api";
import useSessionStore from "@/stores/session";

const useUserStore = defineStore("user", () => {
  const id = ref(null);
  const email = ref(null);
  const username = ref(null);
  const personalId = ref(null);
  const session = useSessionStore();

  async function getUser() {
    const user = await api.getUser();
    id.value = user.id;
    email.value = user.email;
    username.value = user.username;
  }

  function reset() {
    personalId.value = null;
  }

  return {
    id,
    email,
    username,
    personalId,
    getUser,
    reset,
    session,
  };
});

export default useUserStore;
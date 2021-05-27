export default class BaseCollectionClass {
  set(data) {
    this.data = data;

    for (let field in data) {
      this[field] = data[field];
    }
  }
}
